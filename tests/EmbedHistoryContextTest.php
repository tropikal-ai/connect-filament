<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use TropikalAI\Connect\Domain\OAuth\TokenSet;
use TropikalAI\Connect\Domain\Security\SignedRequest;
use TropikalAI\Connect\Domain\Security\SignedRequestContext;
use TropikalAI\ConnectFilament\Contracts\PublicChatActorResolver;
use TropikalAI\ConnectFilament\Domain\PublicChatActor;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Services\ControlPlaneClient;
use TropikalAI\ConnectFilament\Services\PublicChatActorPermit;

final class EmbedHistoryContextTest extends TestCase
{
    public function test_history_identity_requires_both_host_opt_in_and_exact_server_acknowledgement(): void
    {
        $installation = $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        $this->bindActor();
        Http::fake(['*' => Http::response(['items' => []])]);
        foreach ([[false, 'embed-history.v1', 'embed-chat'], [true, '', 'embed-chat'],
            [true, 'future.v2', 'embed-chat'], [true, 'embed-history.v1', 'embed-history']] as [$enabled, $ack, $kind]) {
            config()->set('connect-filament.public_chat.history_actor_context', $enabled);
            $installation->update(['settings' => ['embed_history_protocol' => $ack]]);
            $this->postJson('/tropikal-connect/api/chat/bootstrap', [])->assertOk();
            [$context, $headers] = $this->lastContext($installation);
            $this->assertSame($kind, $context['kind']);
            $this->assertSame('', $context['session_id']);
            $this->assertSame(hash('sha256', ''), $context['actor_context_sha256']);
            $this->assertArrayNotHasKey(PublicChatActorPermit::HEADER, $headers);
            $this->assertSame($kind === 'embed-history', $context['actor_identity'] !== '');
        }
    }

    public function test_member_changes_and_logout_change_only_history_actor_identity_not_the_browser_cookie(): void
    {
        $installation = $this->enabledInstallation();
        $resolver = $this->bindActor();
        $browser = str_repeat('b', 64);
        $this->withCredentials()->withUnencryptedCookie('__Host-tropikal-chat-history', $browser);
        Http::fake(['*' => Http::response(['items' => []])]);
        $identities = [];
        foreach (['42', '43', null] as $member) {
            $resolver->member = $member;
            $this->getJson('/tropikal-connect/api/chat/history')->assertOk();
            [$context, $headers] = $this->lastContext($installation);
            $this->assertSame('embed-history', $context['kind']);
            $this->assertSame($browser, $context['visitor_history_token']);
            $this->assertArrayNotHasKey(PublicChatActorPermit::HEADER, $headers);
            $identities[] = $context['actor_identity'];
        }
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $identities[0]);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $identities[1]);
        $this->assertNotSame($identities[0], $identities[1]);
        $this->assertSame('', $identities[2]);
    }

    public function test_real_first_send_shape_keeps_strict_session_bound_permits_and_never_uses_history_kind(): void
    {
        $installation = $this->enabledInstallation();
        $this->bindActor();
        Http::fake(['*' => Http::response(['status' => 'completed', 'reply' => 'Member chat'])]);
        $session = 'embed_'.Str::uuid();
        $this->postJson('/tropikal-connect/api/chat', ['session_id' => $session, 'message' => 'Member training',
            'message_id' => 'first-chat-message'])->assertOk();
        [$context, $headers] = $this->lastContext($installation);
        $this->assertSame('embed-chat', $context['kind']);
        $this->assertSame($session, $context['session_id']);
        $actor = app(PublicChatActorPermit::class)->redeem($headers[PublicChatActorPermit::HEADER], $installation, $session);
        $this->assertSame('42', $actor?->id);
        $this->assertSame('member', $actor?->type);
    }

    public function test_identity_failure_never_falls_back_to_anonymous_history(): void
    {
        $this->enabledInstallation();
        $resolver = $this->bindActor();
        $resolver->unavailable = true;
        Http::fake();
        $this->postJson('/tropikal-connect/api/chat/bootstrap', [])->assertStatus(502);
        Http::assertNothingSent();
    }

    public function test_history_kind_is_limited_to_history_and_session_routes(): void
    {
        $installation = $this->enabledInstallation();
        $this->bindActor();
        Http::fake(['*' => Http::response(['status' => 'ready'])]);
        $action = '018f1e22-9abc-7def-8123-456789abcdef';
        foreach (['/api/chat/history/'.$action, '/api/chat/session?resume_token=opaque'] as $path) {
            $this->getJson('/tropikal-connect'.$path)->assertOk();
            [$context, $headers] = $this->lastContext($installation);
            $this->assertSame('embed-history', $context['kind']);
            $this->assertArrayNotHasKey(PublicChatActorPermit::HEADER, $headers);
        }
        foreach (['/api/chat/history/'.$action, '/api/chat/history/clear'] as $path) {
            $this->withHeaders(['X-Tropikal-History-Intent' => '1', 'Origin' => 'https://cms.example.com'])
                ->deleteJson('/tropikal-connect'.$path)->assertOk();
            $this->assertSame('embed-history', $this->lastContext($installation)[0]['kind']);
        }
        $body = ['session_id' => 'embed_018f1e22-9abc-7def-8123-456789abcdef', 'resume_token' => 'opaque',
            'message' => 'Training buchen', 'intent' => str_repeat('a', 64), 'decision_capability' => 'opaque'];
        foreach (['/api/chat/auth-intents', '/api/chat/auth-intents/resume', '/api/chat/actions/'.$action.'/status'] as $path) {
            $this->postJson('/tropikal-connect'.$path, $body)->assertOk();
            [$context, $headers] = $this->lastContext($installation);
            $this->assertSame('embed-chat', $context['kind']);
            $this->assertSame($body['session_id'], $context['session_id']);
            $this->assertArrayHasKey(PublicChatActorPermit::HEADER, $headers);
        }
    }

    public function test_registration_persists_only_the_exact_history_protocol_response_header(): void
    {
        foreach (['embed-history.v1', '', 'future.v2'] as $ack) {
            $installation = $this->connectedInstallation(['settings' => ['embed_history_protocol' => 'embed-history.v1']]);
            Http::fake(['*' => Http::response(['installation_id' => 'srv_123', 'server_signing_key' => 'server-signing-key',
                'allowed_resources' => [], 'resource_permissions' => []], 200, ['X-Tropikal-Embed-History-Protocol' => $ack])]);
            app(ControlPlaneClient::class)->registerInstallation($installation, TokenSet::fromArray([
                'access_token' => 'fixture-access', 'refresh_token' => 'fixture-refresh',
            ]));
            $this->assertSame($ack === 'embed-history.v1' ? $ack : null, $installation->fresh()->settings['embed_history_protocol'] ?? null);
            Http::swap(new Factory);
        }
    }

    private function enabledInstallation(): Installation
    {
        config()->set('connect-filament.public_chat.history_actor_context', true);

        return $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED,
            'settings' => ['embed_history_protocol' => 'embed-history.v1']]);
    }

    private function bindActor(): object
    {
        $resolver = new class implements PublicChatActorResolver
        {
            public ?string $member = '42';

            public bool $unavailable = false;

            public function resolve(Request $request): ?PublicChatActor
            {
                if ($this->unavailable) {
                    throw new \RuntimeException('Host identity unavailable');
                }

                return $this->member === null ? null : new PublicChatActor('member', $this->member);
            }
        };
        $this->app->instance(PublicChatActorResolver::class, $resolver);

        return $resolver;
    }

    private function lastContext(Installation $installation): array
    {
        /** @var ClientRequest $request */
        $request = Http::recorded()->last()[0];
        $headers = array_map(fn (array $values): string => $values[0], $request->headers());

        return [json_decode(SignedRequestContext::verify((string) $installation->server_signing_key_encrypted,
            $headers[SignedRequest::SIGNATURE_HEADER], $headers), true, flags: JSON_THROW_ON_ERROR), $headers];
    }
}
