<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use Illuminate\Support\Facades\Storage;
use TropikalAI\ConnectFilament\Models\StagedAsset;
use TropikalAI\ConnectFilament\Services\EloquentDiscovery;
use TropikalAI\ConnectFilament\Services\ResourceRegistry;
use TropikalAI\ConnectFilament\Tests\Fixtures\Post;
use TropikalAI\ConnectFilament\Tests\Fixtures\ReviewedPostAction;
use TropikalAI\ConnectFilament\Tests\Fixtures\ReviewedSourceValidator;

final class OwnerSourceStagingTest extends TestCase
{
    private int $nonce = 0;

    protected function setUp(): void
    {
        parent::setUp();
        ReviewedSourceValidator::$mutate = false;
        Storage::fake('owner_sources');
        config()->set('filesystems.disks.owner_sources.visibility', 'private');
        config()->set('filesystems.disks.owner_sources.driver', 'local');
        config()->set('connect-filament.resources', ['posts' => [
            'model' => Post::class,
            'fields' => ['source' => ['type' => 'asset', 'asset' => [
                'disk' => 'owner_sources', 'directory' => 'incoming', 'max_bytes' => 1024,
                'mime_types' => ['image/png'], 'source_validator' => ReviewedSourceValidator::class,
                'owner_actions' => ['replace'],
            ]]],
            'actions' => ['replace' => ['handler' => ReviewedPostAction::class]],
        ]]);
        $this->app->forgetInstance(ResourceRegistry::class);
        $this->app->singleton(ResourceRegistry::class, fn (): ResourceRegistry => new ResourceRegistry(
            config('connect-filament.resources', []), $this->app->make(EloquentDiscovery::class),
        ));
    }

    private function sourceFiles(): array
    {
        return array_values(array_filter(Storage::disk('owner_sources')->allFiles(),
            static fn (string $path): bool => ! str_starts_with($path, '.owner-source-locks/')));
    }

    private function bytes(): string
    {
        // Valid PNG with an ancillary metadata chunk: sanitization would remove it.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $payload = 'Comment'."\0".'Retained photographic metadata';
        $chunk = 'tEXt'.$payload;

        return substr($png, 0, -12).pack('N', strlen($payload)).$chunk.pack('N', crc32($chunk)).substr($png, -12);
    }

    private function prepare($installation, string $bytes, string $key = 'owner-source:prepare:1')
    {
        return $this->signedJson($installation, 'POST', "/api/tropikal-connect/installations/{$installation->public_id}/assets/prepare", [
            'resource' => 'posts', 'field' => 'source', 'filename' => 'photo.png', 'mime_type' => 'image/png',
            'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'idempotency_key' => $key,
        ], $key.':'.++$this->nonce);
    }

    private function upload($prepared, string $bytes)
    {
        return $this->call('PUT', $prepared->json('upload_url'), [], [], [], [
            'CONTENT_TYPE' => 'image/png', 'HTTP_AUTHORIZATION' => 'Bearer '.$prepared->json('upload_token'),
        ], $bytes);
    }

    public function test_exact_owner_action_grant_stages_original_bytes_privately_and_replays_once(): void
    {
        $owner = $this->connectedInstallation(['allowed_resources' => ['posts'], 'resource_permissions' => ['posts' => ['action:replace']]]);
        $bytes = $this->bytes();
        $prepared = $this->prepare($owner, $bytes)->assertCreated();
        $again = $this->prepare($owner, $bytes)->assertCreated();
        $this->assertSame($prepared->json('asset_ref'), $again->json('asset_ref'));
        $this->upload($prepared, $bytes)->assertOk();
        $asset = StagedAsset::query()->firstOrFail();
        $this->assertSame($bytes, Storage::disk('owner_sources')->get($asset->stored_path));
        $this->assertSame($asset->input_sha256, $asset->stored_sha256);
        $this->assertSame(0600, fileperms(Storage::disk('owner_sources')->path($asset->stored_path)) & 0777);
        $this->upload($prepared, $bytes)->assertOk();
        $this->assertCount(1, $this->sourceFiles());
        $this->assertSame(1, StagedAsset::query()->count());
    }

    public function test_generic_crud_or_other_action_grants_cannot_prepare_the_owner_source(): void
    {
        foreach ([['update'], ['action:review'], []] as $grants) {
            $owner = $this->connectedInstallation(['allowed_resources' => ['posts'], 'resource_permissions' => ['posts' => $grants]]);
            $this->prepare($owner, $this->bytes(), 'denied:'.count($grants).':'.$owner->id)->assertForbidden();
        }
        $this->assertSame(0, StagedAsset::query()->count());
    }

    public function test_revoked_grant_and_invalid_source_refuse_without_private_bytes_or_raw_errors(): void
    {
        $owner = $this->connectedInstallation(['allowed_resources' => ['posts'], 'resource_permissions' => ['posts' => ['action:replace']]]);
        $prepared = $this->prepare($owner, $this->bytes())->assertCreated();
        $owner->update(['resource_permissions' => ['posts' => []]]);
        $this->upload($prepared, $this->bytes())->assertForbidden();
        $owner->update(['resource_permissions' => ['posts' => ['action:replace']]]);
        $bad = $this->prepare($owner, 'invalid-image', 'invalid-source:prepare')->assertCreated();
        config()->set('app.debug', true);
        $this->upload($bad, 'invalid-image')->assertStatus(422)->assertDontSee('private-validator-detail');
        $this->assertSame([], $this->sourceFiles());
        $this->assertSame(0, StagedAsset::query()->where('status', StagedAsset::STATUS_STAGED)->count());
    }

    public function test_source_validator_cannot_publish_to_a_public_disk(): void
    {
        config()->set('filesystems.disks.owner_sources.visibility', 'public');
        $owner = $this->connectedInstallation(['allowed_resources' => ['posts'], 'resource_permissions' => ['posts' => ['action:replace']]]);
        $prepared = $this->prepare($owner, $this->bytes())->assertCreated();
        $this->upload($prepared, $this->bytes())->assertStatus(422);
        $this->assertSame([], $this->sourceFiles());
    }

    public function test_upload_token_expiry_and_oversized_body_cannot_stage_bytes(): void
    {
        $owner = $this->connectedInstallation(['allowed_resources' => ['posts'], 'resource_permissions' => ['posts' => ['action:replace']]]);
        $bytes = $this->bytes();
        $prepared = $this->prepare($owner, $bytes)->assertCreated();
        $this->call('PUT', $prepared->json('upload_url'), [], [], [], [
            'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer wrong-token',
        ], $bytes)->assertUnauthorized();
        $this->upload($prepared, $bytes.str_repeat('x', 4096))->assertStatus(422);
        StagedAsset::query()->firstOrFail()->update(['expires_at' => now()->subSecond()]);
        $this->upload($prepared, $bytes)->assertStatus(410);
        $this->assertSame([], $this->sourceFiles());
    }

    public function test_validator_changed_identity_and_symlink_directory_leave_no_staged_original(): void
    {
        $owner = $this->connectedInstallation(['allowed_resources' => ['posts'], 'resource_permissions' => ['posts' => ['action:replace']]]);
        $bytes = $this->bytes();
        $prepared = $this->prepare($owner, $bytes)->assertCreated();
        ReviewedSourceValidator::$mutate = true;
        $this->upload($prepared, $bytes)->assertStatus(422);
        $this->assertSame([], $this->sourceFiles());
        ReviewedSourceValidator::$mutate = false;
        $root = Storage::disk('owner_sources')->path('');
        rmdir($root.'/incoming');
        mkdir($root.'/outside');
        symlink($root.'/outside', $root.'/incoming');
        try {
            $this->upload($prepared, $bytes)->assertStatus(422);
            $this->assertSame([], scandir($root.'/outside') === ['.', '..'] ? [] : ['unexpected bytes']);
            $this->assertSame(StagedAsset::STATUS_PREPARED, StagedAsset::query()->firstOrFail()->status);
        } finally {
            unlink($root.'/incoming');
        }
    }

    public function test_failed_database_save_retains_one_owned_original_and_retry_reuses_it(): void
    {
        $owner = $this->connectedInstallation(['allowed_resources' => ['posts'], 'resource_permissions' => ['posts' => ['action:replace']]]);
        $bytes = $this->bytes();
        $prepared = $this->prepare($owner, $bytes)->assertCreated();
        $fail = true;
        StagedAsset::saving(function (StagedAsset $asset) use (&$fail): void {
            if ($fail && $asset->status === StagedAsset::STATUS_STAGED) {
                throw new \RuntimeException('private-database-save-detail');
            }
        });
        $this->upload($prepared, $bytes)->assertStatus(422)->assertDontSee('private-database-save-detail');
        $asset = StagedAsset::query()->firstOrFail();
        $this->assertSame(StagedAsset::STATUS_PREPARED, $asset->status);
        $this->assertNull($asset->stored_path);
        $path = 'incoming/'.$asset->public_id.'.png';
        $this->assertSame([$path], $this->sourceFiles());
        $this->assertSame($bytes, Storage::disk('owner_sources')->get($path));
        $absolute = Storage::disk('owner_sources')->path($path);
        touch($absolute, time() - 120);
        $mtime = filemtime($absolute);
        $fail = false;
        $this->upload($prepared, $bytes)->assertOk();
        clearstatcache(true, $absolute);
        $this->assertSame($mtime, filemtime($absolute));
        $this->assertSame([$path], $this->sourceFiles());
        $this->assertSame(StagedAsset::STATUS_STAGED, $asset->fresh()->status);
    }

    public function test_overlapping_source_upload_refuses_without_consuming_preparation(): void
    {
        $owner = $this->connectedInstallation(['allowed_resources' => ['posts'], 'resource_permissions' => ['posts' => ['action:replace']]]);
        $bytes = $this->bytes();
        $prepared = $this->prepare($owner, $bytes)->assertCreated();
        $root = Storage::disk('owner_sources')->path('');
        mkdir($root.'/.owner-source-locks', 0700);
        $lock = fopen($root.'/.owner-source-locks/'.$prepared->json('asset_ref').'.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->upload($prepared, $bytes)->assertStatus(422);
            $this->assertSame(StagedAsset::STATUS_PREPARED, StagedAsset::query()->firstOrFail()->status);
            $this->assertSame([], $this->sourceFiles());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->upload($prepared, $bytes)->assertOk();
        $this->assertCount(1, $this->sourceFiles());
    }
}
