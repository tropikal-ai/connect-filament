<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use TropikalAI\ConnectFilament\Contracts\PublicChatActorResolver;
use TropikalAI\ConnectFilament\Contracts\PublicChatCapabilityProvider;
use TropikalAI\ConnectFilament\Domain\PublicChatActor;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Services\ControlPlaneClient;
use TropikalAI\ConnectFilament\Services\PublicChatCapabilityRegistry;

final class PublicChatCapabilityTest extends TestCase
{
    public function test_registered_capabilities_are_advertised_and_executed_through_the_signed_boundary(): void
    {
        $this->bindProvider();
        $this->assertCount(1, app(PublicChatCapabilityRegistry::class)->manifest());
        $installation = $this->connectedInstallation([
            'allowed_resources' => [],
            'resource_permissions' => [],
        ]);
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/public-chat-capabilities/aoss.member_training.book.v1/query";

        $response = $this->signedCapabilityCall($installation, $path, ['input' => ['days' => 14]], 'cap_query', [
            'X-Tropikal-Actor-Context' => app('TropikalAI\\ConnectFilament\\Services\\PublicChatActorPermit')
                ->issue(new PublicChatActor('member', '42'), $installation, 'session-1'),
            'X-Tropikal-Chat-Session' => 'session-1',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'available')
            ->assertJsonPath('actor_id', '42');

        $schemaPath = "/api/tropikal-connect/installations/{$installation->public_id}/control-plane-resources";
        $this->signedGet($installation, $schemaPath, null, 'cap_schema')
            ->assertOk()
            ->assertJsonPath('public_chat_capabilities.0.kind', 'aoss.member_training.book.v1');
    }

    public function test_member_capability_fails_closed_without_a_valid_actor_permit(): void
    {
        $this->bindProvider();
        $this->assertCount(1, app(PublicChatCapabilityRegistry::class)->manifest());
        $installation = $this->connectedInstallation();
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/public-chat-capabilities/aoss.member_training.book.v1/query";

        $anonymous = $this->signedJson($installation, 'POST', $path, ['input' => ['days' => 14]], 'cap_anon');
        $anonymous
            ->assertUnauthorized()
            ->assertJsonPath('error', 'member_authentication_required');
    }

    public function test_execute_is_idempotent_and_bound_to_the_same_input(): void
    {
        $provider = $this->bindProvider();
        $installation = $this->connectedInstallation();
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/public-chat-capabilities/aoss.member_training.book.v1/execute";
        $permit = app('TropikalAI\\ConnectFilament\\Services\\PublicChatActorPermit')
            ->issue(new PublicChatActor('member', '42'), $installation, 'session-1');
        $headers = [
            'X-Tropikal-Actor-Context' => $permit,
            'X-Tropikal-Initial-Actor-Context' => $permit,
            'X-Tropikal-Chat-Session' => 'session-1',
            'X-Tropikal-Idempotency-Key' => 'chat-action-1',
        ];

        $first = $this->signedCapabilityCall($installation, $path, ['input' => ['option_ref' => 'slot-1']], 'cap_execute_1', $headers);
        $second = $this->signedCapabilityCall($installation, $path, ['input' => ['option_ref' => 'slot-1']], 'cap_execute_2', $headers);

        $first->assertOk()->assertJsonPath('result_ref', 'booking-1');
        $second->assertOk()
            ->assertJsonPath('result_ref', 'booking-1')
            ->assertJsonPath('operation_receipt.replayed', true);
        $this->assertSame(1, $provider->executeCount);

        $this->signedCapabilityCall(
            $installation,
            $path,
            ['input' => ['option_ref' => 'slot-2']],
            'cap_execute_3',
            $headers,
        )->assertConflict()->assertJsonPath('error', 'idempotency_conflict');
    }

    public function test_member_execution_requires_the_same_live_actor_that_prepared_the_review(): void
    {
        $this->bindProvider();
        $installation = $this->connectedInstallation();
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/public-chat-capabilities/aoss.member_training.book.v1/execute";
        $permits = app('TropikalAI\\ConnectFilament\\Services\\PublicChatActorPermit');

        $this->signedCapabilityCall(
            $installation,
            $path,
            ['input' => ['option_ref' => 'slot-1']],
            'cap_actor_changed',
            [
                'X-Tropikal-Actor-Context' => $permits->issue(new PublicChatActor('member', '84'), $installation, 'session-1'),
                'X-Tropikal-Initial-Actor-Context' => $permits->issue(new PublicChatActor('member', '42'), $installation, 'session-1'),
                'X-Tropikal-Chat-Session' => 'session-1',
                'X-Tropikal-Idempotency-Key' => 'chat-action-actor-changed',
            ],
        )->assertUnauthorized()->assertJsonPath('error', 'member_identity_changed');
    }

    public function test_capability_sync_requires_an_exact_control_plane_manifest_acknowledgement(): void
    {
        $this->bindProvider();
        $installation = $this->connectedInstallation();
        $registry = app(PublicChatCapabilityRegistry::class);
        Http::fake([
            'https://auth.example.com/oauth/token' => Http::response([
                'access_token' => 'access_456',
                'refresh_token' => 'refresh_456',
                'expires_in' => 300,
            ]),
            'https://control.example.com/api/connect-filament/installations' => Http::response([
                'installation_id' => 'srv_123',
                'server_signing_key' => 'server-signing-key',
                'allowed_resources' => [],
                'resource_permissions' => [],
                'embed' => ['status' => Installation::EMBED_NOT_ENABLED],
            ], 200, [
                'X-Tropikal-Public-Chat-Protocol' => 'public_chat_actions.v1',
                'X-Tropikal-Public-Chat-Manifest-Sha256' => $registry->manifestHash(),
                'X-Tropikal-Public-Chat-Accepted-Kinds' => 'aoss.member_training.book.v1',
            ]),
        ]);

        app(ControlPlaneClient::class)->syncCapabilities($installation);

        $this->assertSame('srv_123', $installation->fresh()->control_plane_installation_id);
    }

    private function bindProvider(): object
    {
        $provider = new class implements PublicChatCapabilityProvider
        {
            public int $executeCount = 0;

            public function capabilities(): array
            {
                return [[
                    'kind' => 'aoss.member_training.book.v1',
                    'title' => 'Book group training',
                    'description' => 'Find and book an eligible group training.',
                    'audience' => 'member',
                    'query_tool' => [
                        'name' => 'aoss_member_training_options',
                        'description' => 'Return eligible member training options.',
                        'input_schema' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => ['days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 28]],
                        ],
                    ],
                    'proposal_input_schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['option_ref'],
                        'properties' => ['option_ref' => ['type' => 'string']],
                    ],
                    'execution_input_schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['option_ref'],
                        'properties' => ['option_ref' => ['type' => 'string', 'minLength' => 1]],
                    ],
                ]];
            }

            public function query(string $kind, array $input, ?PublicChatActor $actor): array
            {
                return ['status' => 'available', 'actor_id' => $actor?->id];
            }

            public function execute(string $kind, array $input, ?PublicChatActor $actor): array
            {
                $this->executeCount++;

                return ['result_ref' => 'booking-1', 'status' => 'succeeded'];
            }
        };
        $this->app->instance(PublicChatCapabilityProvider::class, $provider);
        $this->app->instance(PublicChatActorResolver::class, new class implements PublicChatActorResolver
        {
            public function resolve(Request $request): ?PublicChatActor
            {
                return null;
            }
        });

        return $provider;
    }

    private function signedCapabilityCall($installation, string $path, array $payload, string $nonce, array $headers)
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->withHeaders([
            ...$this->sign($installation, 'POST', $path, null, $body, $nonce),
            ...$headers,
        ])->json('POST', $path, $payload);
    }
}
