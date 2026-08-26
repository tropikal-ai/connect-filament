<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Models\StagedAsset;
use TropikalAI\ConnectFilament\Services\ResourceRegistry;
use TropikalAI\ConnectFilament\Services\StagedAssetManager;

final class AssetController extends Controller
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly StagedAssetManager $assets,
    ) {}

    public function prepare(Request $request): JsonResponse
    {
        $installation = $request->attributes->get('connect_filament_installation');
        if (! $installation instanceof Installation) {
            abort(403, 'Connect installation is not connected.');
        }

        $input = $request->validate([
            'resource' => ['required', 'string', 'max:120'],
            'field' => ['required', 'string', 'max:120'],
            'filename' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string', Rule::in((array) config('connect-filament.assets.mime_types', []))],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.(int) config('connect-filament.assets.max_bytes', 5 * 1024 * 1024)],
            'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:160', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]+$/'],
        ]);
        $resourceSlug = (string) $input['resource'];
        $field = (string) $input['field'];
        $resource = $this->registry->allowedResource($installation, $resourceSlug);
        $definition = is_array($resource['fields'][$field] ?? null) ? $resource['fields'][$field] : null;
        if (
            $resource === null
            || ! is_array($definition)
            || ($definition['type'] ?? null) !== 'asset'
            || ($definition['writable'] ?? true) === false
            || ! ($this->registry->allows($installation, $resourceSlug, 'create')
                || $this->registry->allows($installation, $resourceSlug, 'update'))
        ) {
            abort(403, 'Asset field is not allowed for this installation.');
        }

        $assetSettings = is_array($definition['asset'] ?? null) ? $definition['asset'] : [];
        if (! in_array($input['mime_type'], (array) ($assetSettings['mime_types'] ?? []), true)) {
            abort(422, 'Image type is not allowed for this field.');
        }
        if ((int) $input['size_bytes'] > (int) ($assetSettings['max_bytes'] ?? config('connect-filament.assets.max_bytes'))) {
            abort(422, 'Image is too large for this field.');
        }

        $asset = $this->assets->prepare($installation, $resourceSlug, $field, $input, $definition);

        return response()->json([
            'asset_ref' => $asset->public_id,
            'upload_url' => route('connect-filament.api.assets.upload', ['assetRef' => $asset->public_id]),
            'upload_method' => 'PUT',
            'upload_token' => $asset->upload_token_encrypted,
            'expires_at' => $asset->expires_at?->toISOString(),
        ], 201);
    }

    public function upload(Request $request, string $assetRef): JsonResponse
    {
        $asset = StagedAsset::query()->where('public_id', $assetRef)->firstOrFail();
        $asset = $this->assets->storeUpload($asset, $request);

        return response()->json([
            'asset_ref' => $asset->public_id,
            'status' => $asset->status,
            'mime_type' => $asset->mime_type,
            'size_bytes' => $asset->size_bytes,
        ]);
    }
}
