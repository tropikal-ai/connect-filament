<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use TropikalAI\ConnectFilament\Contracts\PublicChatCapabilityProvider;
use TropikalAI\ConnectFilament\Domain\PublicChatActor;
use TropikalAI\ConnectFilament\Services\PublicChatActorPermit;
use TropikalAI\ConnectFilament\Services\PublicChatCapabilityRegistry;

final class QueryOnlyPublicChatCapabilityTest extends TestCase
{
    public function test_query_only_manifest_needs_no_effect_contract(): void
    {
        $this->bindProvider();
        $manifest = app(PublicChatCapabilityRegistry::class)->manifest();

        $this->assertCount(1, $manifest);
        $this->assertTrue($manifest[0]['query_only']);
        $this->assertArrayNotHasKey('proposal_input_schema', $manifest[0]);
        $this->assertArrayNotHasKey('execution_input_schema', $manifest[0]);
    }

    public function test_query_only_mode_rejects_non_boolean_values(): void
    {
        $provider = $this->bindProvider();
        foreach ([1, 0, 'true', 'false', null, [], new \stdClass] as $invalid) {
            $provider->definition['query_only'] = $invalid;
            $this->assertSame([], (new PublicChatCapabilityRegistry($provider))->manifest());
        }
    }

    public function test_absent_and_false_modes_keep_the_existing_action_manifest_identical(): void
    {
        $provider = $this->bindProvider();
        unset($provider->definition['query_only']);
        $provider->definition['proposal_input_schema'] = $provider->definition['query_tool']['input_schema'];
        $provider->definition['execution_input_schema'] = $provider->definition['query_tool']['input_schema'];
        $registry = new PublicChatCapabilityRegistry($provider);
        $before = $registry->manifest();
        $hash = $registry->manifestHash();
        $provider->definition['query_only'] = false;

        $this->assertSame($before, $registry->manifest());
        $this->assertSame($hash, $registry->manifestHash());
        $this->assertArrayNotHasKey('query_only', $before[0]);
        unset($provider->definition['execution_input_schema']);
        $this->assertSame([], $registry->manifest());
    }

    public function test_signed_query_runs_but_execute_fails_before_actor_or_mutation_admission(): void
    {
        $provider = $this->bindProvider();
        $installation = $this->connectedInstallation();
        $base = "/api/tropikal-connect/installations/{$installation->public_id}/public-chat-capabilities/aoss.catalogue.read.v1/";

        $this->signedJson($installation, 'POST', $base.'query', ['input' => ['locale' => 'de']], 'catalogue_query')
            ->assertOk()->assertJsonPath('items.0.id', 'team-single');
        $this->assertSame(1, $provider->queryCount);
        $this->signedJson($installation, 'POST', $base.'query', ['input' => ['unknown' => true]], 'catalogue_bad_query')
            ->assertUnprocessable()->assertJsonPath('error', 'invalid_capability_input');

        $provider->definition['audience'] = 'member';
        foreach ([['input' => ['locale' => 'de']], ['input' => 'invalid']] as $index => $payload) {
            $this->signedJson($installation, 'POST', $base.'execute', $payload, 'catalogue_execute_'.$index)
                ->assertStatus(405)->assertJsonPath('error', 'query_only_capability');
        }
        $this->assertSame(0, $provider->executeCount);
        $this->assertSame(1, $provider->queryCount);
        $this->assertDatabaseCount('connect_filament_operation_receipts', 0);
    }

    public function test_member_queries_still_require_the_matching_live_actor_permit(): void
    {
        $provider = $this->bindProvider();
        $provider->definition['audience'] = 'member';
        $installation = $this->connectedInstallation();
        $path = "/api/tropikal-connect/installations/{$installation->public_id}/public-chat-capabilities/aoss.catalogue.read.v1/query";
        $this->signedJson($installation, 'POST', $path, ['input' => ['locale' => 'de']], 'member_catalogue_denied')
            ->assertUnauthorized()->assertJsonPath('error', 'member_authentication_required');
        $this->assertSame(0, $provider->queryCount);

        $payload = ['input' => ['locale' => 'de']];
        $permit = app(PublicChatActorPermit::class)->issue(new PublicChatActor('member', '42'), $installation, 'catalogue-session');
        $this->withHeaders([
            ...$this->sign($installation, 'POST', $path, null, json_encode($payload, JSON_THROW_ON_ERROR), 'member_catalogue_allowed'),
            PublicChatActorPermit::HEADER => $permit,
            PublicChatActorPermit::SESSION_HEADER => 'catalogue-session',
        ])->json('POST', $path, $payload)->assertOk()->assertJsonPath('actor_id', '42');
        $this->assertSame(1, $provider->queryCount);
    }

    private function bindProvider(): object
    {
        $provider = new class implements PublicChatCapabilityProvider
        {
            public int $queryCount = 0;

            public int $executeCount = 0;

            public array $definition = [
                'kind' => 'aoss.catalogue.read.v1',
                'title' => 'School catalogue',
                'description' => 'Read the current published school offers.',
                'audience' => 'public',
                'query_only' => true,
                'query_tool' => [
                    'name' => 'aoss_catalogue',
                    'description' => 'Return current offers.',
                    'input_schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => ['locale' => ['type' => 'string', 'enum' => ['de', 'en']]],
                    ],
                ],
            ];

            public function capabilities(): array
            {
                return [$this->definition];
            }

            public function query(string $kind, array $input, ?PublicChatActor $actor): array
            {
                $this->queryCount++;

                return ['items' => [['id' => 'team-single']], 'actor_id' => $actor?->id];
            }

            public function execute(string $kind, array $input, ?PublicChatActor $actor): array
            {
                $this->executeCount++;

                throw new \LogicException('A catalogue cannot execute.');
            }
        };
        $this->app->instance(PublicChatCapabilityProvider::class, $provider);

        return $provider;
    }
}
