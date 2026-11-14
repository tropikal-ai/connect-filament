<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use Illuminate\Support\Facades\Crypt;
use TropikalAI\ConnectFilament\Contracts\PublicChatCapabilityProvider;
use TropikalAI\ConnectFilament\Domain\PublicChatActor;
use TropikalAI\ConnectFilament\Domain\PublicChatCapabilityException;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Models\OperationReceipt;
use TropikalAI\ConnectFilament\Services\PublicChatActorPermit;
use TropikalAI\ConnectFilament\Services\PublicChatCapabilityRegistry;

final class PublicChatReceiptStatusTest extends TestCase
{
    public function test_receipt_support_is_explicit_and_invalid_versions_are_not_admitted(): void
    {
        $provider = $this->provider();
        $registry = new PublicChatCapabilityRegistry($provider);
        $this->assertSame('public-chat-receipt.v1', $registry->manifest()[0]['execution_receipts']);
        foreach ([true, 1, null, 'future.v2'] as $invalid) {
            $provider->definition['execution_receipts'] = $invalid;
            $this->assertSame([], $registry->manifest());
        }
        unset($provider->definition['execution_receipts']);
        $this->assertArrayNotHasKey('execution_receipts', $registry->manifest()[0]);
        $provider->definition['query_only'] = true;
        $provider->definition['execution_receipts'] = 'public-chat-receipt.v1';
        $this->assertSame([], $registry->manifest());
    }

    public function test_status_reads_the_original_committed_result_without_executing_again(): void
    {
        $provider = $this->provider();
        $installation = $this->connectedInstallation();
        $this->capabilityCall($installation, 'execute')->assertOk()->assertJsonPath('result_ref', 'booking-1');
        $this->assertSame(1, $provider->executions);
        $receipt = OperationReceipt::query()->sole();
        $this->capabilityCall($installation, 'status')->assertOk()
            ->assertJsonPath('schema', 'public-chat-receipt.v1')
            ->assertJsonPath('status', 'committed')
            ->assertJsonPath('response_status', 200)
            ->assertJsonPath('result.result_ref', 'booking-1')
            ->assertJsonPath('operation_receipt.result_ref', 'booking-1')
            ->assertJsonPath('operation_receipt.id', $receipt->public_id);
        $this->assertSame(1, $provider->executions);
        $this->assertDatabaseCount('connect_filament_operation_receipts', 1);
    }

    public function test_public_guest_receipt_survives_login_without_disclosing_stored_contact_or_history(): void
    {
        $provider = $this->provider();
        $provider->definition['audience'] = 'public';
        $installation = $this->connectedInstallation();
        $this->capabilityCall($installation, 'execute', actor: null)->assertOk();
        OperationReceipt::query()->sole()->forceFill(['response_json' => ['status' => 'succeeded', 'result_ref' => 'booking-1',
            'attendee_name' => 'Private attendee', 'attendee_email' => 'private@example.test', 'history' => ['private conversation']]])->save();
        $response = $this->capabilityCall($installation, 'status', actor: '42')->assertOk()
            ->assertJsonPath('status', 'committed')->assertJsonPath('result', ['status' => 'succeeded', 'result_ref' => 'booking-1']);
        $this->assertStringNotContainsString('private', strtolower($response->getContent()));
        $this->assertSame(1, $provider->executions);
    }

    public function test_missing_receipt_is_unknown_and_never_admits_an_effect(): void
    {
        $provider = $this->provider();
        $installation = $this->connectedInstallation();
        $this->capabilityCall($installation, 'status')->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('result', null);
        $this->assertSame(0, $provider->executions);
        $this->assertDatabaseCount('connect_filament_operation_receipts', 0);
    }

    public function test_status_and_retry_are_bound_to_the_original_actor_session_input_and_installation(): void
    {
        $provider = $this->provider();
        $installation = $this->connectedInstallation();
        $this->capabilityCall($installation, 'execute')->assertOk();
        $this->capabilityCall($installation, 'status', actor: '84')->assertConflict()->assertJsonMissing(['result_ref' => 'booking-1']);
        $this->capabilityCall($installation, 'execute', actor: '84')->assertConflict();
        $this->capabilityCall($installation, 'status', session: 'other-session')->assertConflict();
        $this->capabilityCall($installation, 'status', input: ['option_ref' => 'slot-2'])->assertConflict();
        $this->capabilityCall($installation, 'status', initialActor: '84')->assertUnauthorized();
        $this->capabilityCall($installation, 'status', actor: null)->assertUnauthorized();
        $other = $this->connectedInstallation();
        $this->capabilityCall($other, 'status')->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('result', null);
        $this->assertSame(1, $provider->executions);
    }

    public function test_opted_in_effects_require_an_idempotency_key_and_bounded_session(): void
    {
        $provider = $this->provider();
        $installation = $this->connectedInstallation();
        foreach (['execute', 'status'] as $operation) {
            $this->capabilityCall($installation, $operation, key: '')->assertStatus(428);
            $this->capabilityCall($installation, $operation, session: '')->assertUnprocessable();
        }
        $this->assertSame(0, $provider->executions);
        $this->assertDatabaseCount('connect_filament_operation_receipts', 0);
    }

    public function test_failed_and_claimed_receipts_are_distinguished_from_missing_receipts(): void
    {
        $provider = $this->provider();
        $provider->rejected = true;
        $installation = $this->connectedInstallation();
        $this->capabilityCall($installation, 'execute')->assertConflict();
        $this->capabilityCall($installation, 'status')->assertOk()
            ->assertJsonPath('status', 'failed_no_effect')->assertJsonPath('response_status', 409)
            ->assertJsonPath('result.error', 'already_trialed');
        OperationReceipt::query()->sole()->forceFill(['status' => OperationReceipt::STATUS_CLAIMED,
            'response_json' => null, 'response_status' => null])->save();
        $this->capabilityCall($installation, 'status')->assertOk()->assertJsonPath('status', 'claimed')->assertJsonPath('result', null);
        $this->assertSame(1, $provider->executions);
    }

    public function test_expired_initial_permit_does_not_block_receipt_recovery_but_cannot_execute(): void
    {
        $this->provider();
        $installation = $this->connectedInstallation();
        $this->capabilityCall($installation, 'execute')->assertOk();
        $expired = Crypt::encryptString(json_encode([
            'v' => 1, 'installation_id' => $installation->public_id, 'session_id' => 'session-1',
            'actor_type' => 'member', 'actor_id' => '42', 'expires_at' => now()->subHour()->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
        $this->capabilityCall($installation, 'status', initialPermit: $expired)->assertOk()->assertJsonPath('status', 'committed');
        $this->capabilityCall($installation, 'execute', initialPermit: $expired)->assertUnauthorized();
        $this->capabilityCall($installation, 'status', actor: '84', initialPermit: $expired)->assertConflict();
    }

    public function test_old_capabilities_have_no_status_support_and_keep_their_execution_contract(): void
    {
        $provider = $this->provider();
        unset($provider->definition['execution_receipts']);
        $installation = $this->connectedInstallation();
        $this->capabilityCall($installation, 'status')->assertNotFound();
        $this->capabilityCall($installation, 'execute', key: '')->assertOk();
        $this->assertSame(1, $provider->executions);
    }

    private function capabilityCall(Installation $installation, string $operation, ?string $actor = '42', string $session = 'session-1',
        ?string $initialActor = null, array $input = ['option_ref' => 'slot-1'], string $key = 'action:018f1e22-9abc-7def-8123-456789abcdef', ?string $initialPermit = null)
    {
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/public-chat-capabilities/aoss.member_training.book.v1/{$operation}";
        $payload = ['input' => $input];
        $permits = app(PublicChatActorPermit::class);
        $permit = fn (?string $id) => $id !== null && $session !== '' ? $permits->issue(new PublicChatActor('member', $id), $installation, $session) : '';

        return $this->withHeaders([
            ...$this->sign($installation, 'POST', $path, null, json_encode($payload, JSON_THROW_ON_ERROR), 'receipt_'.bin2hex(random_bytes(8))),
            PublicChatActorPermit::HEADER => $permit($actor),
            PublicChatActorPermit::INITIAL_HEADER => $initialPermit ?? $permit($initialActor ?? $actor),
            PublicChatActorPermit::SESSION_HEADER => $session,
            'X-Tropikal-Idempotency-Key' => $key,
        ])->json('POST', $path, $payload);
    }

    private function provider(): object
    {
        $provider = new class implements PublicChatCapabilityProvider
        {
            public int $executions = 0;

            public bool $rejected = false;

            public array $definition = [
                'kind' => 'aoss.member_training.book.v1', 'title' => 'Book training', 'description' => 'Book an eligible member training.',
                'audience' => 'member', 'execution_receipts' => 'public-chat-receipt.v1',
                'query_tool' => ['name' => 'member_training_options', 'description' => 'Read available member training.',
                    'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['days' => ['type' => 'integer']]]],
                'proposal_input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['option_ref'],
                    'properties' => ['option_ref' => ['type' => 'string']]],
                'execution_input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['option_ref'],
                    'properties' => ['option_ref' => ['type' => 'string']]],
            ];

            public function capabilities(): array
            {
                return [$this->definition];
            }

            public function query(string $kind, array $input, ?PublicChatActor $actor): array
            {
                return ['options' => []];
            }

            public function execute(string $kind, array $input, ?PublicChatActor $actor): array
            {
                $this->executions++;
                if ($this->rejected) {
                    throw new PublicChatCapabilityException('already_trialed', 'A trial already exists.', 409);
                }

                return ['status' => 'succeeded', 'result_ref' => 'booking-1'];
            }
        };
        $this->app->instance(PublicChatCapabilityProvider::class, $provider);

        return $provider;
    }
}
