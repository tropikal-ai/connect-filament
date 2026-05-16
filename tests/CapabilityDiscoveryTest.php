<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use TropikalAI\Connect\Domain\Security\SensitiveData;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Services\CapabilityGrantManager;
use TropikalAI\ConnectFilament\Services\ControlPlaneClient;
use TropikalAI\ConnectFilament\Services\EloquentDiscovery;
use TropikalAI\ConnectFilament\Services\ResourceRegistry;
use TropikalAI\ConnectFilament\Tests\Fixtures\Post;
use TropikalAI\ConnectFilament\Tests\Fixtures\User;

final class CapabilityDiscoveryTest extends TestCase
{
    public function test_eloquent_discovery_finds_safe_business_models_and_excludes_auth_models(): void
    {
        $resources = app(EloquentDiscovery::class)->discover();

        $this->assertArrayHasKey('posts', $resources);
        $this->assertSame(Post::class, $resources['posts']['model']);
        $this->assertArrayHasKey('title', $resources['posts']['fields']);
        $this->assertArrayNotHasKey('secret_note', $resources['posts']['fields']);
        $this->assertNotContains(User::class, array_column($resources, 'model'));
    }

    public function test_empty_discovery_namespaces_do_not_scan_every_loaded_model(): void
    {
        config()->set('connect-filament.resources', []);
        config()->set('connect-filament.discovery.model_classes', []);
        config()->set('connect-filament.discovery.included_model_namespaces', []);

        $this->assertSame([], app(EloquentDiscovery::class)->discover());
    }

    public function test_default_grants_expose_nothing(): void
    {
        $installation = $this->connectedInstallation();

        $this->assertSame([], app(ResourceRegistry::class)->schemaFor($installation));
        $this->assertSame([], app(ResourceRegistry::class)->controlPlaneResourcesFor($installation));
    }

    public function test_grant_checkboxes_create_only_explicit_capabilities(): void
    {
        $installation = $this->connectedInstallation();
        $manager = app(CapabilityGrantManager::class);

        $installation = $manager->set($installation, 'posts', 'read', true);
        $this->assertSame(['posts'], $installation->allowed_resources);
        $this->assertSame(['read'], $installation->resource_permissions['posts']);

        $installation = $manager->set($installation, 'posts', 'write', true);
        $this->assertSame(['read', 'create', 'update'], $installation->resource_permissions['posts']);
        $this->assertNotContains('delete', $installation->resource_permissions['posts']);

        $schema = app(ResourceRegistry::class)->controlPlaneResourcesFor($installation);
        $operations = array_column($schema['posts']['capabilities'], 'operation');
        $this->assertSame(['list', 'get', 'create', 'update'], $operations);
        SensitiveData::assertPublicPayload($schema);

        $installation = $manager->set($installation, 'posts', 'delete', true);
        $this->assertSame(['read', 'create', 'update', 'delete'], $installation->resource_permissions['posts']);

        $schema = app(ResourceRegistry::class)->controlPlaneResourcesFor($installation);
        $operations = array_column($schema['posts']['capabilities'], 'operation');
        $this->assertSame(['list', 'get', 'create', 'update', 'delete'], $operations);

        $delete = collect($schema['posts']['capabilities'])->firstWhere('operation', 'delete');
        $this->assertSame('destructive', $delete['risk_level']);
        $this->assertTrue($delete['requires_confirmation']);
        $this->assertSame(['id'], $delete['input_schema']['required']);
        SensitiveData::assertPublicPayload($schema);
    }

    public function test_list_capability_advertises_pagination_search_and_safe_filters(): void
    {
        config()->set('connect-filament.resources', []);
        $installation = $this->connectedInstallation([
            'allowed_resources' => ['articles'],
            'resource_permissions' => ['articles' => ['read']],
        ]);

        $operation = collect(app(ResourceRegistry::class)->controlPlaneResourcesFor($installation)['articles']['capabilities'])
            ->firstWhere('operation', 'list');

        $properties = $operation['input_schema']['properties'];
        $this->assertArrayHasKey('page', $properties);
        $this->assertArrayHasKey('per_page', $properties);
        $this->assertArrayHasKey('limit', $properties);
        $this->assertArrayHasKey('search', $properties);
        $this->assertArrayHasKey('slug', $properties);
        $this->assertArrayHasKey('category', $properties);
        $this->assertArrayNotHasKey('content', $properties);
        $this->assertFalse($operation['input_schema']['additionalProperties']);
        SensitiveData::assertPublicPayload($operation);
    }

    public function test_capability_sync_payload_contains_only_safe_granted_resources(): void
    {
        $installation = app(CapabilityGrantManager::class)->set($this->connectedInstallation(), 'posts', 'read', true);

        Http::fake([
            'https://auth.example.com/oauth/token' => Http::response([
                'access_token' => 'access_456',
                'refresh_token' => 'refresh_456',
                'expires_in' => 300,
            ]),
            'https://control.example.com/api/connect-filament/installations' => Http::response([
                'installation_id' => 'srv_123',
                'server_signing_key' => 'server-signing-key',
                'allowed_resources' => ['posts'],
                'resource_permissions' => ['posts' => ['read']],
                'account' => ['id' => 'acct_123', 'email' => 'owner@example.com'],
                'embed' => ['status' => Installation::EMBED_NOT_ENABLED],
            ]),
        ]);

        app(ControlPlaneClient::class)->syncCapabilities($installation);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://control.example.com/api/connect-filament/installations') {
                return false;
            }

            $payload = $request->data();
            SensitiveData::assertPublicPayload($payload);

            return isset($payload['resources']['posts'])
                && $payload['resources']['posts']['permissions'] === ['read']
                && ! isset($payload['resources']['posts']['fields']['secret_note']);
        });
    }
}
