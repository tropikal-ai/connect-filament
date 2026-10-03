<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use TropikalAI\ConnectFilament\Models\AuditLog;
use TropikalAI\ConnectFilament\Models\OperationReceipt;
use TropikalAI\ConnectFilament\Services\ResourceRegistry;
use TropikalAI\ConnectFilament\Tests\Fixtures\Post;
use TropikalAI\ConnectFilament\Tests\Fixtures\ReviewedPostAction;

final class OwnerResourceActionTest extends TestCase
{
    private function declaration(): array
    {
        return [
            'label' => 'Review selected posts', 'handler' => ReviewedPostAction::class,
            'input_schema' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['ids', 'expected_revisions'],
                'properties' => [
                    'ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 2, 'uniqueItems' => true,
                        'items' => ['type' => 'integer', 'minimum' => 1]],
                    'expected_revisions' => ['type' => 'object', 'maxProperties' => 2,
                        'propertyNames' => ['pattern' => '^[1-9][0-9]*$'],
                        'additionalProperties' => ['type' => 'integer', 'minimum' => 1]],
                ],
            ],
        ];
    }

    private function configureAction(): void
    {
        $this->configurePostResource();
        config()->set('connect-filament.resources.posts.actions.review', $this->declaration());
        config()->set('connect-filament.api.require_idempotency_for_mutations', true);
        ReviewedPostAction::$calls = 0;
        ReviewedPostAction::$failure = null;
    }

    public function test_only_granted_typed_actions_are_discovered_with_confirmation_and_no_handler_leak(): void
    {
        $this->configureAction();
        $installation = $this->connectedInstallation([
            'allowed_resources' => ['posts'], 'resource_permissions' => ['posts' => ['action:review']],
        ]);
        $registry = $this->app->make(ResourceRegistry::class);
        $resource = $registry->controlPlaneResourcesFor($installation)['posts'];
        $this->assertSame($this->declaration()['input_schema'], $resource['actions']['review']['input_schema']);
        $this->assertArrayNotHasKey('handler', $resource['actions']['review']);
        $this->assertSame('review', $resource['capabilities'][0]['operation']);
        $this->assertTrue($resource['capabilities'][0]['requires_confirmation']);
        $installation->update(['resource_permissions' => ['posts' => ['read']]]);
        $resource = $registry->controlPlaneResourcesFor($installation)['posts'];
        $this->assertSame([], $resource['actions']);
        $this->assertNotContains('review', array_column($resource['capabilities'], 'operation'));
    }

    public function test_signed_collection_action_replays_once_and_reapplies_field_grants(): void
    {
        $this->configureAction();
        $post = Post::query()->create(['title' => 'Before', 'body' => 'Private field']);
        $installation = $this->connectedInstallation([
            'allowed_resources' => ['posts'], 'resource_permissions' => ['posts' => ['action:review']],
        ]);
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/resources/posts/actions/review";
        $payload = ['ids' => [$post->id], 'expected_revisions' => [$post->id => 1]];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (['first', 'replay'] as $nonce) {
            if ($nonce === 'replay') {
                $installation->update(['resource_permissions' => ['posts' => ['action:review', 'fields:selected', 'field:title']]]);
            }
            $response = $this->withHeaders([
                ...$this->sign($installation, 'POST', $path, null, $body, $nonce),
                'X-Tropikal-Idempotency-Key' => 'review:collection:1',
            ])->json('POST', $path, $payload)->assertOk()->assertJsonPath('data.0.title', 'Reviewed');
            if ($nonce === 'replay') {
                $response->assertJsonMissingPath('data.0.body')->assertJsonPath('operation_receipt.replayed', true);
            }
        }
        $this->assertSame(1, ReviewedPostAction::$calls);
        $this->assertSame(1, OperationReceipt::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'action:review')->count());
    }

    public function test_collection_action_refuses_missing_grant_and_invalid_typed_arguments_before_mutation(): void
    {
        $this->configureAction();
        $installation = $this->connectedInstallation([
            'allowed_resources' => ['posts'], 'resource_permissions' => ['posts' => ['update']],
        ]);
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/resources/posts/actions/review";
        $this->signedJson($installation, 'POST', $path, ['ids' => [1], 'expected_revisions' => [1 => 1]], 'forbidden')->assertForbidden();
        $installation->update(['resource_permissions' => ['posts' => ['action:review']]]);
        foreach ([['ids' => ['1'], 'expected_revisions' => [1 => 1]],
            ['ids' => [1, 2, 3], 'expected_revisions' => [1 => 1]],
            ['ids' => [1, 1], 'expected_revisions' => [1 => 1]],
            ['ids' => [1], 'expected_revisions' => [1 => 0]],
            ['ids' => [1], 'expected_revisions' => [1 => 1], 'method' => 'delete']] as $index => $input) {
            $this->signedJson($installation, 'POST', $path, $input, 'invalid_'.$index)->assertStatus(422);
        }
        $this->assertSame(0, ReviewedPostAction::$calls);
        $this->assertSame(0, OperationReceipt::query()->count());
    }

    public function test_collection_action_requires_receipt_key_and_refuses_cross_installation_signature(): void
    {
        $this->configureAction();
        $installation = $this->connectedInstallation([
            'allowed_resources' => ['posts'], 'resource_permissions' => ['posts' => ['action:review']],
        ]);
        $other = $this->connectedInstallation([
            'allowed_resources' => ['posts'], 'resource_permissions' => ['posts' => ['action:review']],
            'server_signing_key_encrypted' => 'independent-installation-key',
        ]);
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/resources/posts/actions/review";
        $payload = ['ids' => [1], 'expected_revisions' => [1 => 1]];
        $this->signedJson($installation, 'POST', $path, $payload, 'missing_receipt')->assertStatus(428);
        $otherPath = "/api/tropikal-connect/installations/{$other->public_id}/resources/posts/actions/review";
        $headers = $this->sign($installation, 'POST', $otherPath, null, json_encode($payload, JSON_THROW_ON_ERROR), 'cross_installation');
        $this->withHeaders($headers)->json('POST', $otherPath, $payload)->assertUnauthorized();
        $this->assertSame(0, ReviewedPostAction::$calls);
        $this->assertSame(0, OperationReceipt::query()->count());
    }

    public function test_receipt_conflict_and_revoked_grant_do_not_repeat_the_handler(): void
    {
        $this->configureAction();
        $post = Post::query()->create(['title' => 'Before', 'body' => 'Private field']);
        $installation = $this->connectedInstallation([
            'allowed_resources' => ['posts'], 'resource_permissions' => ['posts' => ['action:review', 'action:publish']],
        ]);
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/resources/posts/actions/review";
        $payload = ['ids' => [$post->id], 'expected_revisions' => [$post->id => 1]];
        $send = function (array $input, string $nonce) use ($installation, $path) {
            return $this->withHeaders([
                ...$this->sign($installation, 'POST', $path, null, json_encode($input, JSON_THROW_ON_ERROR), $nonce),
                'X-Tropikal-Idempotency-Key' => 'review:collection:conflict',
            ])->json('POST', $path, $input);
        };
        $send($payload, 'commit')->assertOk();
        $send(['ids' => [$post->id], 'expected_revisions' => [$post->id => 2]], 'conflict')->assertStatus(409);
        $installation->update(['resource_permissions' => ['posts' => ['update', 'action:publish']]]);
        $send($payload, 'revoked')->assertForbidden();
        $legacyPath = str_replace('/actions/review', '/actions/publish', $path);
        $this->signedJson($installation, 'POST', $legacyPath, [], 'no_legacy_fallthrough')->assertNotFound();
        $this->assertSame(1, ReviewedPostAction::$calls);
        $this->assertSame(1, OperationReceipt::query()->count());
    }

    public function test_handler_errors_are_redacted_even_in_debug_and_roll_back_changes_and_receipts(): void
    {
        $this->configureAction();
        config()->set('app.debug', true);
        $post = Post::query()->create(['title' => 'Before', 'body' => 'Private field']);
        $installation = $this->connectedInstallation([
            'allowed_resources' => ['posts'], 'resource_permissions' => ['posts' => ['action:review']],
        ]);
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/resources/posts/actions/review";
        $payload = ['ids' => [$post->id], 'expected_revisions' => [$post->id => 1]];
        foreach (['validation' => 422, 'runtime' => 500] as $failure => $status) {
            ReviewedPostAction::$failure = $failure;
            $this->withHeaders([
                ...$this->sign($installation, 'POST', $path, null, json_encode($payload, JSON_THROW_ON_ERROR), 'failure_'.$failure),
                'X-Tropikal-Idempotency-Key' => 'review:failure:'.$failure,
            ])->json('POST', $path, $payload)->assertStatus($status)
                ->assertDontSee('private-native-source-detail')
                ->assertJsonPath('error', $status === 422 ? 'Invalid resource data' : 'Resource mutation failed');
            $this->assertSame('Before', $post->fresh()->title);
            $this->assertSame(0, OperationReceipt::query()->count());
            $this->assertSame(0, AuditLog::query()->count());
        }
        ReviewedPostAction::$failure = null;
    }
}
