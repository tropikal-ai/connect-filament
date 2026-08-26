<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use Illuminate\Support\Facades\Storage;
use TropikalAI\ConnectFilament\Models\StagedAsset;
use TropikalAI\ConnectFilament\Services\EloquentDiscovery;
use TropikalAI\ConnectFilament\Services\ResourceRegistry;
use TropikalAI\ConnectFilament\Tests\Fixtures\Post;

final class AssetApiTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config()->set('connect-filament.api.require_idempotency_for_mutations', true);
        config()->set('connect-filament.resources', [
            'posts' => [
                'label' => 'Posts',
                'model' => Post::class,
                'identifier' => 'id',
                'sort_column' => 'id',
                'operation_risks' => ['create' => 'publish', 'update' => 'publish'],
                'fields' => [
                    'title' => ['type' => 'string', 'required' => true],
                    'body' => [
                        'type' => 'asset',
                        'label' => 'Main image',
                        'description' => 'Required image shown on the public post.',
                        'asset' => [
                            'disk' => 'public',
                            'directory' => 'news',
                            'max_bytes' => 5 * 1024 * 1024,
                            'mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
                        ],
                    ],
                ],
            ],
        ]);
        $this->app->forgetInstance(ResourceRegistry::class);
        $this->app->singleton(ResourceRegistry::class, fn (): ResourceRegistry => new ResourceRegistry(
            config('connect-filament.resources', []),
            $this->app->make(EloquentDiscovery::class),
        ));
    }

    public function test_prepared_image_upload_is_owned_validated_and_consumed_by_resource_create(): void
    {
        $installation = $this->connectedInstallation([
            'allowed_resources' => ['posts'],
            'resource_permissions' => ['posts' => ['create', 'update']],
        ]);
        $createCapability = collect($this->app->make(ResourceRegistry::class)
            ->controlPlaneResourcesFor($installation)['posts']['capabilities'])
            ->firstWhere('operation', 'create');
        $this->assertSame('publish', $createCapability['risk_level']);
        $this->assertSame('tropikal-asset-ref', $createCapability['input_schema']['properties']['body']['format']);
        $this->assertSame('Main image', $createCapability['input_schema']['properties']['body']['title']);
        $this->assertSame(
            'Required image shown on the public post.',
            $createCapability['input_schema']['properties']['body']['description'],
        );
        $bytes = base64_decode(self::PNG, true);
        $preparePath = "/api/tropikal-connect/installations/{$installation->public_id}/assets/prepare";
        $preparePayload = [
            'resource' => 'posts',
            'field' => 'body',
            'filename' => 'training.png',
            'mime_type' => 'image/png',
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'idempotency_key' => 'telegram:file_unique_123:posts:body',
        ];

        $prepared = $this->signedJson(
            $installation,
            'POST',
            $preparePath,
            $preparePayload,
            'asset_prepare_1',
        )->assertCreated();

        $preparedReplay = $this->signedJson(
            $installation,
            'POST',
            $preparePath,
            $preparePayload,
            'asset_prepare_2',
        )->assertCreated();

        $this->assertSame($prepared->json('asset_ref'), $preparedReplay->json('asset_ref'));
        $this->assertSame($prepared->json('upload_token'), $preparedReplay->json('upload_token'));
        $this->assertSame(1, StagedAsset::query()->count());

        $this->call(
            'PUT',
            $prepared->json('upload_url'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'image/png',
                'HTTP_AUTHORIZATION' => 'Bearer '.$prepared->json('upload_token'),
            ],
            $bytes,
        )
            ->assertOk()
            ->assertJsonPath('asset_ref', $prepared->json('asset_ref'));

        $createPath = "/api/tropikal-connect/installations/{$installation->public_id}/resources/posts";
        $created = $this->withHeaders([
            ...$this->sign(
                $installation,
                'POST',
                $createPath,
                null,
                json_encode([
                    'title' => 'Training update',
                    'body' => $prepared->json('asset_ref'),
                ], JSON_THROW_ON_ERROR),
                'asset_commit_1',
            ),
            'X-Tropikal-Idempotency-Key' => 'review:asset-create:attempt:1',
        ])->json('POST', $createPath, [
            'title' => 'Training update',
            'body' => $prepared->json('asset_ref'),
        ])->assertCreated();

        $path = $created->json('data.body');
        $this->assertStringStartsWith('news/', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertSame(StagedAsset::STATUS_COMMITTED, StagedAsset::query()->firstOrFail()->status);
    }

    public function test_asset_from_another_installation_cannot_cross_the_mutation_boundary(): void
    {
        $owner = $this->connectedInstallation([
            'allowed_resources' => ['posts'],
            'resource_permissions' => ['posts' => ['create', 'update']],
        ]);
        $attacker = $this->connectedInstallation([
            'allowed_resources' => ['posts'],
            'resource_permissions' => ['posts' => ['create', 'update']],
        ]);
        $asset = StagedAsset::query()->create([
            'installation_id' => $owner->getKey(),
            'prepare_idempotency_key' => 'telegram:owned_asset:posts:body',
            'request_hash' => str_repeat('a', 64),
            'resource_slug' => 'posts',
            'field_name' => 'body',
            'upload_token_encrypted' => 'upload-secret',
            'status' => StagedAsset::STATUS_STAGED,
            'disk' => 'public',
            'directory' => 'news',
            'stored_path' => 'news/owned.png',
            'mime_type' => 'image/png',
            'size_bytes' => 68,
            'input_sha256' => str_repeat('b', 64),
            'stored_sha256' => str_repeat('c', 64),
            'expires_at' => now()->addHour(),
            'uploaded_at' => now(),
        ]);
        $path = "/api/tropikal-connect/installations/{$attacker->public_id}/resources/posts";
        $payload = ['title' => 'Forbidden', 'body' => $asset->public_id];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->withHeaders([
            ...$this->sign($attacker, 'POST', $path, null, $body, 'asset_cross_owner_1'),
            'X-Tropikal-Idempotency-Key' => 'review:cross-owner:attempt:1',
        ])->json('POST', $path, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);

        $this->assertSame(0, Post::query()->count());
        $this->assertSame(StagedAsset::STATUS_STAGED, $asset->fresh()->status);
    }
}
