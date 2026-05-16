<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use TropikalAI\Connect\Domain\Security\SensitiveData;
use TropikalAI\Connect\Domain\Security\SignedRequest;
use TropikalAI\ConnectFilament\Models\AuditLog;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Services\EloquentDiscovery;
use TropikalAI\ConnectFilament\Services\ResourceRegistry;
use TropikalAI\ConnectFilament\Tests\Fixtures\Article;
use TropikalAI\ConnectFilament\Tests\Fixtures\Post;

final class ResourceApiTest extends TestCase
{
    public function test_empty_resource_allow_list_exposes_nothing(): void
    {
        $this->configurePostResource();
        $installation = $this->connectedInstallation();
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/schema";

        $this->signedGet($installation, $path, null, 'schema_empty')
            ->assertOk()
            ->assertExactJson(['resources' => []]);
    }

    public function test_declared_resources_appear_only_after_grants(): void
    {
        $this->configurePostResource();
        $installation = $this->connectedInstallation([
            'allowed_resources' => ['posts'],
            'resource_permissions' => ['posts' => ['read']],
        ]);
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/schema";

        $response = $this->signedGet($installation, $path, null, 'schema_granted')
            ->assertOk()
            ->json('resources.posts');

        $this->assertSame('Posts', $response['label']);
        $this->assertSame([], $response['actions']);
    }

    public function test_undeclared_and_ungranted_resources_are_rejected(): void
    {
        $this->configurePostResource();
        $installation = $this->connectedInstallation([
            'allowed_resources' => ['posts'],
            'resource_permissions' => ['posts' => ['read']],
        ]);

        $unknownPath = "/api/tropikal-connect/installations/{$installation->public_id}/resources/pages";
        $this->signedGet($installation, $unknownPath, null, 'unknown_resource')
            ->assertNotFound();

        $blocked = $this->connectedInstallation([
            'allowed_resources' => [],
            'resource_permissions' => ['posts' => ['read']],
        ]);
        $blockedPath = "/api/tropikal-connect/installations/{$blocked->public_id}/resources/posts";
        $this->signedGet($blocked, $blockedPath, null, 'blocked_resource')
            ->assertForbidden();
    }

    public function test_reads_project_declared_fields_only(): void
    {
        $this->configurePostResource();
        $installation = $this->connectedInstallation([
            'allowed_resources' => ['posts'],
            'resource_permissions' => ['posts' => ['read']],
        ]);
        $post = Post::query()->create([
            'title' => 'Visible',
            'body' => 'Readable',
            'secret_note' => 'hidden',
        ]);
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/resources/posts/{$post->id}";

        $data = $this->signedGet($installation, $path, null, 'show_post')
            ->assertOk()
            ->json('data');

        $this->assertSame('Visible', $data['title']);
        $this->assertArrayNotHasKey('secret_note', $data);
    }

    public function test_readable_false_fields_are_writeable_but_never_projected(): void
    {
        config()->set('connect-filament.resources', [
            'posts' => [
                'label' => 'Posts',
                'model' => Post::class,
                'identifier' => 'id',
                'sort_column' => 'id',
                'fields' => [
                    'title' => ['type' => 'string', 'required' => true],
                    'body' => ['type' => 'text', 'readable' => false, 'writable' => true],
                ],
            ],
        ]);
        $this->app->forgetInstance(ResourceRegistry::class);
        $this->app->singleton(ResourceRegistry::class, fn (): ResourceRegistry => new ResourceRegistry(
            config('connect-filament.resources', []),
            $this->app->make(EloquentDiscovery::class),
        ));

        $installation = $this->connectedInstallation([
            'allowed_resources' => ['posts'],
            'resource_permissions' => ['posts' => ['read', 'create']],
        ]);
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/resources/posts";

        $schema = $this->signedGet($installation, "/api/tropikal-connect/installations/{$installation->public_id}/schema", null, 'writeonly_schema')
            ->assertOk()
            ->json('resources.posts');

        $this->assertArrayHasKey('title', $schema['fields']);
        $this->assertArrayNotHasKey('body', $schema['fields']);

        $created = $this->signedJson($installation, 'POST', $path, [
            'title' => 'Write-only body',
            'body' => 'Stored but not returned',
        ], 'writeonly_create')->assertCreated()->json('data');

        $this->assertSame('Write-only body', $created['title']);
        $this->assertArrayNotHasKey('body', $created);
        $this->assertSame('Stored but not returned', Post::query()->firstOrFail()->body);
    }

    public function test_writes_reject_unknown_fields_and_mutations_write_audit_logs(): void
    {
        $this->configurePostResource();
        $installation = $this->connectedInstallation([
            'allowed_resources' => ['posts'],
            'resource_permissions' => ['posts' => ['create']],
        ]);
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/resources/posts";

        $this->signedJson($installation, 'POST', $path, [
            'title' => 'Draft',
            'secret_note' => 'hidden',
        ], 'create_unknown')->assertStatus(422);

        $this->signedJson($installation, 'POST', $path, [
            'title' => 'Draft',
            'body' => 'Body',
        ], 'create_valid')->assertCreated();

        $this->assertSame(1, AuditLog::query()->where('action', 'create')->count());
    }

    public function test_discovered_create_schema_requires_non_nullable_safe_fields_and_not_generated_slug(): void
    {
        config()->set('connect-filament.resources', []);
        $installation = $this->connectedInstallation([
            'allowed_resources' => ['articles'],
            'resource_permissions' => ['articles' => ['create', 'update']],
        ]);
        $createPath = "/api/tropikal-connect/installations/{$installation->public_id}/resources/articles";

        $operation = collect($this->app->make(ResourceRegistry::class)
            ->controlPlaneResourcesFor($installation)['articles']['capabilities'])
            ->firstWhere('operation', 'create');

        $this->assertSame(['title', 'content'], $operation['input_schema']['required']);
        $this->assertArrayHasKey('title', $operation['input_schema']['properties']);
        $this->assertArrayHasKey('content', $operation['input_schema']['properties']);
        $this->assertArrayNotHasKey('slug', array_flip($operation['input_schema']['required']));

        $this->signedJson($installation, 'POST', $createPath, [], 'article_empty')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'content']);

        $created = $this->signedJson($installation, 'POST', $createPath, [
            'title' => 'Discovered Article',
            'content' => 'Readable body',
        ], 'article_valid')->assertCreated()->json('data');

        $this->assertSame('Discovered Article', $created['title']);
        $this->assertSame('discovered-article', Article::query()->firstOrFail()->slug);
    }

    public function test_list_supports_pagination_search_and_safe_exact_filters(): void
    {
        config()->set('connect-filament.resources', []);
        $installation = $this->connectedInstallation([
            'allowed_resources' => ['articles'],
            'resource_permissions' => ['articles' => ['read']],
        ]);
        Article::query()->create(['title' => 'First', 'slug' => 'first', 'content' => 'Alpha', 'category' => 'Research']);
        Article::query()->create(['title' => 'No Response', 'slug' => 'no-response', 'content' => 'Beta', 'category' => 'AI']);
        Article::query()->create(['title' => 'Third', 'slug' => 'third', 'content' => 'Gamma', 'category' => 'AI']);

        $path = "/api/tropikal-connect/installations/{$installation->public_id}/resources/articles";

        $page = $this->signedGet($installation, $path, 'per_page=2&page=2', 'articles_page')
            ->assertOk()
            ->json();

        $this->assertSame(2, $page['meta']['current_page']);
        $this->assertSame(2, $page['meta']['per_page']);
        $this->assertSame(3, $page['meta']['total']);
        $this->assertCount(1, $page['data']);

        $searched = $this->signedGet($installation, $path, 'search=no-response&per_page=10', 'articles_search')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $searched);
        $this->assertSame('no-response', $searched[0]['slug']);

        $filtered = $this->signedGet($installation, $path, 'slug=no-response&category=AI', 'articles_filter')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $filtered);
        $this->assertSame('No Response', $filtered[0]['title']);
    }

    public function test_delete_requires_explicit_destructive_grant(): void
    {
        $this->configurePostResource();
        $installation = $this->connectedInstallation([
            'allowed_resources' => ['posts'],
            'resource_permissions' => ['posts' => ['create', 'update']],
        ]);
        $post = Post::query()->create([
            'title' => 'Draft',
            'body' => 'Body',
        ]);
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/resources/posts/{$post->id}";

        $this->withHeaders([
            ...$this->sign($installation, 'DELETE', $path, null, '', 'delete_denied'),
            'Accept' => 'application/json',
        ])->delete($path)->assertForbidden();

        $this->assertTrue(Post::query()->whereKey($post->id)->exists());

        $installation->forceFill(['resource_permissions' => ['posts' => ['create', 'update', 'delete']]])->save();

        $data = $this->withHeaders([
            ...$this->sign($installation->refresh(), 'DELETE', $path, null, '', 'delete_allowed'),
            'Accept' => 'application/json',
        ])->delete($path)->assertOk()->json('data');

        $this->assertSame((string) $post->id, $data['id']);
        $this->assertTrue($data['deleted']);
        $this->assertFalse(Post::query()->whereKey($post->id)->exists());
        $this->assertSame(1, AuditLog::query()->where('action', 'delete')->count());
    }

    public function test_named_actions_require_explicit_grants(): void
    {
        $this->configurePostResource();
        $installation = $this->connectedInstallation([
            'allowed_resources' => ['posts'],
            'resource_permissions' => ['posts' => ['read']],
        ]);
        $post = Post::query()->create([
            'title' => 'Draft',
            'body' => 'Body',
        ]);
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/resources/posts/{$post->id}/actions/publish";

        $this->signedJson($installation, 'POST', $path, [], 'action_denied')
            ->assertForbidden();

        $installation->forceFill(['resource_permissions' => ['posts' => ['read', 'action:publish']]])->save();

        $this->signedJson($installation->refresh(), 'POST', $path, [], 'action_allowed')
            ->assertOk();

        $this->assertNotNull($post->refresh()->published_at);
        $this->assertSame(1, AuditLog::query()->where('action', 'action:publish')->count());
    }

    public function test_public_status_payload_contains_no_secret_shaped_keys(): void
    {
        $this->connectedInstallation([
            'account_email' => 'owner@example.com',
            'workspace_id' => 'workspace_123',
            'embed_status' => 'enabled',
            'embed_public_id' => 'embed_123',
            'settings' => [
                'website' => [
                    'detail_url' => 'https://website.example.com/websites/website_123',
                ],
            ],
        ]);

        $payload = $this->getJson(route('connect-filament.embed.info'))
            ->assertOk()
            ->json();

        SensitiveData::assertPublicPayload($payload);
        $this->assertSame('https://website.example.com/websites/website_123', $payload['website']['detail_url']);
        $this->assertArrayNotHasKey('workspace_id', $payload['account']);
        $this->assertArrayNotHasKey('oauth_refresh_token_encrypted', $payload);
        $this->assertArrayNotHasKey('server_signing_key_encrypted', $payload);
    }

    public function test_public_chat_embed_asset_is_rewritten_to_configured_prefix(): void
    {
        config()->set('connect-filament.embed.asset_rewrite_prefixes', ['/legacy-connect']);

        Http::fake([
            'https://control.example.com/embed/chat-widget.js' => Http::response(
                "fetch('/legacy-connect/api/chat/info');",
                200,
                ['Content-Type' => 'application/javascript; charset=utf-8'],
            ),
        ]);

        $this->get('/tropikal-connect/embed/chat-widget.js')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee('/tropikal-connect/api/chat/info', false)
            ->assertDontSee('/legacy-connect', false);
    }

    public function test_public_chat_info_returns_not_enabled_without_connected_embed(): void
    {
        $this->getJson('/tropikal-connect/api/chat/info')
            ->assertStatus(503)
            ->assertExactJson([
                'error' => 'chat_not_enabled',
                'message' => 'Website chat is not enabled for this site.',
            ]);
    }

    public function test_public_chat_info_hides_control_plane_credential_errors(): void
    {
        $this->connectedInstallation([
            'embed_status' => Installation::EMBED_ENABLED,
        ]);

        Http::fake([
            'https://control.example.com/api/connect-filament/embed/info*' => Http::response([
                'detail' => 'Connect installation is missing server credentials',
            ], 401),
        ]);

        $this->getJson('/tropikal-connect/api/chat/info')
            ->assertStatus(503)
            ->assertExactJson([
                'error' => 'chat_not_enabled',
                'message' => 'Website chat is not enabled for this site.',
            ])
            ->assertJsonMissing(['detail' => 'Connect installation is missing server credentials']);
    }

    public function test_public_chat_info_repairs_stale_control_plane_credentials_and_retries(): void
    {
        $installation = $this->connectedInstallation([
            'embed_status' => Installation::EMBED_ENABLED,
            'server_signing_key_encrypted' => 'old-server-signing-key',
        ]);

        Http::fake([
            'https://control.example.com/api/connect-filament/embed/info*' => Http::sequence()
                ->push(['detail' => 'Connect installation is missing server credentials'], 401)
                ->push([
                    'display_name' => 'Example Front Desk',
                    'avatar_url' => 'https://example.com/avatar.png',
                    'welcome_message' => 'Hi.',
                    'theme' => ['name' => 'tropikal'],
                    'capability_disclosures' => [],
                ], 200),
            'https://auth.example.com/oauth/token' => Http::response([
                'access_token' => 'access-after-repair',
                'refresh_token' => 'refresh-after-repair',
            ]),
            'https://control.example.com/api/connect-filament/installations' => Http::response([
                'installation_id' => 'remote_installation_123',
                'server_signing_key' => 'new-server-signing-key',
                'allowed_resources' => [],
                'resource_permissions' => [],
                'account' => [
                    'id' => 'owner_123',
                    'email' => 'owner@example.com',
                    'workspace_id' => 'workspace_123',
                ],
                'embed' => [
                    'status' => Installation::EMBED_ENABLED,
                    'public_id' => 'embed_123',
                    'display_name' => 'Example Front Desk',
                ],
            ]),
        ]);

        $this->getJson('/tropikal-connect/api/chat/info')
            ->assertOk()
            ->assertJsonPath('display_name', 'Example Front Desk')
            ->assertJsonMissing(['detail' => 'Connect installation is missing server credentials']);

        $installation->refresh();
        $this->assertSame('new-server-signing-key', $installation->server_signing_key_encrypted);
        $this->assertSame('refresh-after-repair', $installation->oauth_refresh_token_encrypted);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://control.example.com/api/connect-filament/installations'
            && $request->hasHeader('Authorization')
            && $request['installation_public_id'] === $installation->public_id
            && ! isset($request['server_signing_key'], $request['refresh_token']));
    }

    public function test_public_chat_api_routes_use_api_middleware_not_web_sessions(): void
    {
        foreach (['connect-filament.embed.chat.info', 'connect-filament.embed.chat'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $middleware = $route->gatherMiddleware();

            $this->assertContains('api', $middleware);
            $this->assertNotContains('web', $middleware);
        }
    }

    public function test_public_chat_post_proxies_with_signed_connect_request(): void
    {
        $installation = $this->connectedInstallation([
            'embed_status' => 'enabled',
        ]);

        Http::fake([
            'https://control.example.com/api/connect-filament/embed/chat' => Http::response([
                'message' => 'Hello from the workflow.',
            ]),
        ]);

        $this->postJson('/tropikal-connect/api/chat', [
            'message' => 'Hello',
            'session_id' => 'embed_session_123',
        ], [
            'X-Embed-Origin' => 'https://cms.example.com',
        ])->assertOk()
            ->assertJsonPath('message', 'Hello from the workflow.');

        Http::assertSent(function (Request $request) use ($installation): bool {
            return $request->url() === 'https://control.example.com/api/connect-filament/embed/chat'
                && $request->method() === 'POST'
                && $request->hasHeader(SignedRequest::INSTALLATION_HEADER, (string) $installation->public_id)
                && $request->hasHeader(SignedRequest::SIGNATURE_HEADER)
                && $request->hasHeader('X-Embed-Origin', 'https://cms.example.com')
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_public_chat_proxy_ignores_invalid_declared_embed_origin(): void
    {
        $this->connectedInstallation([
            'embed_status' => 'enabled',
        ]);

        Http::fake([
            'https://control.example.com/api/connect-filament/embed/info*' => Http::response([
                'display_name' => 'Example Front Desk',
            ]),
        ]);

        $this->getJson('/tropikal-connect/api/chat/info', [
            'X-Embed-Origin' => 'javascript:alert(1)',
            'Referer' => 'https://cms.example.com/projects',
        ])->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Embed-Origin', 'https://cms.example.com'));
    }

    public function test_public_chat_info_proxies_with_signed_connect_request(): void
    {
        $installation = $this->connectedInstallation([
            'embed_status' => 'enabled',
        ]);

        Http::fake([
            'https://control.example.com/api/connect-filament/embed/info*' => Http::response([
                'display_name' => 'Example Front Desk',
                'avatar_url' => 'https://example.com/avatar.png',
                'welcome_message' => 'Hi.',
                'theme' => ['name' => 'tropikal'],
                'capability_disclosures' => [],
            ]),
        ]);

        $this->getJson('/tropikal-connect/api/chat/info?b=2&a=1', [
            'X-Embed-Origin' => 'https://cms.example.com',
        ])->assertOk()
            ->assertJsonPath('display_name', 'Example Front Desk');

        Http::assertSent(function (Request $request) use ($installation): bool {
            return $request->url() === 'https://control.example.com/api/connect-filament/embed/info?a=1&b=2'
                && $request->hasHeader(SignedRequest::INSTALLATION_HEADER, (string) $installation->public_id)
                && $request->hasHeader(SignedRequest::SIGNATURE_HEADER)
                && $request->hasHeader(SignedRequest::BODY_HASH_HEADER, hash('sha256', ''))
                && $request->hasHeader('X-Embed-Origin', 'https://cms.example.com')
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_public_chat_info_uses_control_plane_when_local_embed_status_is_stale(): void
    {
        $installation = $this->connectedInstallation([
            'embed_status' => Installation::EMBED_NOT_ENABLED,
        ]);

        Http::fake([
            'https://control.example.com/api/connect-filament/embed/info*' => Http::response([
                'display_name' => 'Example Front Desk',
                'welcome_message' => 'Hi.',
                'theme' => ['name' => 'tropikal'],
                'capability_disclosures' => [],
            ]),
        ]);

        $this->getJson('/tropikal-connect/api/chat/info')
            ->assertOk()
            ->assertJsonPath('display_name', 'Example Front Desk');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://control.example.com/api/connect-filament/embed/info'
            && $request->hasHeader(SignedRequest::INSTALLATION_HEADER, (string) $installation->public_id));
    }
}
