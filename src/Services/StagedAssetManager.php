<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Models\StagedAsset;

final class StagedAssetManager
{
    public function __construct(private readonly ImageSanitizer $images) {}

    public function prepare(Installation $installation, string $resourceSlug, string $field, array $input, array $definition): StagedAsset
    {
        $requestValues = $input;
        unset($requestValues['idempotency_key']);
        ksort($requestValues);
        $requestHash = hash('sha256', json_encode($requestValues, JSON_THROW_ON_ERROR));
        $key = (string) $input['idempotency_key'];

        try {
            return DB::transaction(function () use (
                $definition,
                $field,
                $input,
                $installation,
                $key,
                $requestHash,
                $resourceSlug,
            ): StagedAsset {
                $existing = $this->preparedByKey($installation, $key, lock: true);
                if ($existing !== null) {
                    return $this->assertSamePreparation($existing, $requestHash);
                }

                $asset = is_array($definition['asset'] ?? null) ? $definition['asset'] : [];
                $directory = trim((string) ($asset['directory'] ?? 'tropikal-connect'), '/');
                if ($directory === '' || str_contains($directory, '..')) {
                    throw new \InvalidArgumentException('Asset directory configuration is invalid.');
                }

                return StagedAsset::query()->create([
                    'installation_id' => $installation->getKey(),
                    'prepare_idempotency_key' => $key,
                    'request_hash' => $requestHash,
                    'resource_slug' => $resourceSlug,
                    'field_name' => $field,
                    'upload_token_encrypted' => Str::random(64),
                    'status' => StagedAsset::STATUS_PREPARED,
                    'disk' => (string) ($asset['disk'] ?? 'public'),
                    'directory' => $directory,
                    'original_filename' => (string) $input['filename'],
                    'mime_type' => (string) $input['mime_type'],
                    'size_bytes' => (int) $input['size_bytes'],
                    'input_sha256' => (string) $input['sha256'],
                    'expires_at' => now()->addSeconds(max(300, (int) config('connect-filament.assets.ttl_seconds', 900))),
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->preparedByKey($installation, $key, lock: false);
            if ($existing === null) {
                throw $exception;
            }

            return $this->assertSamePreparation($existing, $requestHash);
        }
    }

    public function storeUpload(StagedAsset $asset, Request $request): StagedAsset
    {
        return DB::transaction(function () use ($asset, $request): StagedAsset {
            $locked = StagedAsset::query()
                ->whereKey($asset->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            return $this->storeUploadLocked($locked, $request);
        }, 3);
    }

    private function storeUploadLocked(StagedAsset $asset, Request $request): StagedAsset
    {
        $token = $request->bearerToken();
        if (! is_string($token) || ! hash_equals((string) $asset->upload_token_encrypted, $token)) {
            abort(401, 'Upload capability is invalid.');
        }
        if ($asset->expires_at?->isPast()) {
            abort(410, 'Upload capability expired.');
        }

        $bytes = $request->getContent();
        if ($asset->status === StagedAsset::STATUS_STAGED) {
            if (hash_equals((string) $asset->input_sha256, hash('sha256', $bytes))) {
                return $asset;
            }
            abort(409, 'This upload capability was already used for different content.');
        }
        if ($asset->status !== StagedAsset::STATUS_PREPARED) {
            abort(409, 'This upload capability can no longer be used.');
        }
        if (strlen($bytes) !== (int) $asset->size_bytes || ! hash_equals((string) $asset->input_sha256, hash('sha256', $bytes))) {
            abort(422, 'Uploaded bytes do not match the prepared asset.');
        }

        $resource = app(ResourceRegistry::class)->resource((string) $asset->resource_slug) ?? [];
        $definition = is_array($resource['fields'][$asset->field_name] ?? null)
            ? $resource['fields'][$asset->field_name]
            : [];
        $settings = is_array($definition['asset'] ?? null) ? $definition['asset'] : [];
        $allowedMimeTypes = array_values((array) ($settings['mime_types'] ?? config('connect-filament.assets.mime_types', [])));
        $maxBytes = (int) ($settings['max_bytes'] ?? config('connect-filament.assets.max_bytes', 5 * 1024 * 1024));
        if (strlen($bytes) > $maxBytes) {
            abort(422, 'Uploaded image is too large.');
        }

        try {
            $image = $this->images->sanitize($bytes, $allowedMimeTypes);
        } catch (\InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }
        if ($image['mime_type'] !== $asset->mime_type) {
            abort(422, 'Uploaded image type does not match the prepared asset.');
        }

        $path = trim((string) $asset->directory, '/').'/'.Str::uuid().'.'.$image['extension'];
        if (! Storage::disk((string) $asset->disk)->put($path, $image['bytes'])) {
            abort(500, 'The uploaded image could not be stored.');
        }

        $asset->forceFill([
            'status' => StagedAsset::STATUS_STAGED,
            'stored_path' => $path,
            'stored_sha256' => hash('sha256', $image['bytes']),
            'uploaded_at' => now(),
        ])->save();

        return $asset;
    }

    public function resolveForMutation(
        Installation $installation,
        string $resourceSlug,
        array $resource,
        array $values,
    ): array {
        foreach ($values as $field => $value) {
            $definition = is_array($resource['fields'][$field] ?? null) ? $resource['fields'][$field] : [];
            if (($definition['type'] ?? null) !== 'asset' || $value === null) {
                continue;
            }

            $asset = StagedAsset::query()
                ->where('public_id', (string) $value)
                ->where('installation_id', $installation->getKey())
                ->where('resource_slug', $resourceSlug)
                ->where('field_name', $field)
                ->lockForUpdate()
                ->first();
            if (
                $asset === null
                || $asset->status !== StagedAsset::STATUS_STAGED
                || $asset->expires_at?->isPast()
                || ! is_string($asset->stored_path)
            ) {
                throw ValidationException::withMessages([
                    $field => ['The staged asset is invalid, expired, already used, or belongs to another Website.'],
                ]);
            }

            $values[$field] = $asset->stored_path;
            $asset->forceFill([
                'status' => StagedAsset::STATUS_COMMITTED,
                'committed_at' => now(),
            ])->save();
        }

        return $values;
    }

    private function preparedByKey(Installation $installation, string $key, bool $lock): ?StagedAsset
    {
        $query = StagedAsset::query()
            ->where('installation_id', $installation->getKey())
            ->where('prepare_idempotency_key', $key);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function assertSamePreparation(StagedAsset $asset, string $requestHash): StagedAsset
    {
        if (! hash_equals((string) $asset->request_hash, $requestHash)) {
            abort(409, 'This idempotency key was already used for a different asset.');
        }

        return $asset;
    }
}
