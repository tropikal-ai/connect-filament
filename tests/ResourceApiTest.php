<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use TropikalAI\Connect\Domain\Security\SensitiveData;
use TropikalAI\ConnectFilament\Models\AuditLog;
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
        ]);

        $payload = $this->getJson(route('connect-filament.embed.info'))
            ->assertOk()
            ->json();

        SensitiveData::assertPublicPayload($payload);
        $this->assertArrayNotHasKey('oauth_refresh_token_encrypted', $payload);
        $this->assertArrayNotHasKey('server_signing_key_encrypted', $payload);
    }
}
