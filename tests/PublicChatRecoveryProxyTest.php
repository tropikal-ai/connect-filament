<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use TropikalAI\ConnectFilament\Contracts\PublicChatActorResolver;
use TropikalAI\ConnectFilament\Domain\PublicChatActor;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Services\PublicChatActorPermit;

final class PublicChatRecoveryProxyTest extends TestCase
{
    public function test_status_forwards_only_the_existing_decision_without_verification_or_registration_writes(): void
    {
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        $action = '018f1e22-9abc-7def-8123-456789abcdef';
        $expected = ['decision_capability' => 'opaque-decision', 'resume_token' => 'opaque-resume', 'session_id' => 'session-1'];
        Http::fake(['*' => Http::response(['status' => 'unknown', 'outcome' => ['schema' => 'public_action_outcome.v1', 'status' => 'unknown']])]);
        $this->postJson('/tropikal-connect/api/chat/actions/'.$action.'/status', [...$expected,
            'proof_of_work_token' => 'must-not-forward', 'human_verified' => true])->assertOk()->assertJsonPath('outcome.status', 'unknown');
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://control.example.com/api/connect-filament/embed/actions/'.$action.'/status'
            && json_decode($request->body(), true) === $expected);
    }

    public function test_recovery_authorization_errors_do_not_repair_registration_or_hide_the_typed_denial(): void
    {
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        Http::fake(['*' => Http::response(['error' => 'member_identity_changed'], 401)]);
        $this->postJson('/tropikal-connect/api/chat/actions/018f1e22-9abc-7def-8123-456789abcdef/status', [
            'decision_capability' => 'opaque-decision', 'resume_token' => 'opaque-resume', 'session_id' => 'session-1',
        ])->assertUnauthorized()->assertJsonPath('error', 'member_identity_changed');
        Http::assertSentCount(1);
    }

    public function test_auth_intent_mint_forwards_only_the_inert_request_in_json(): void
    {
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        $input = ['session_id' => 'session-1', 'resume_token' => 'opaque-resume', 'message' => 'Buche mein Training am Freitag.'];
        Http::fake(['*' => Http::response(['schema' => 'chat_auth_intent.v1', 'status' => 'authentication_required', 'intent' => str_repeat('a', 64)])]);
        $this->postJson('/tropikal-connect/api/chat/auth-intents', [...$input, 'actor_id' => 'forged', 'callback_url' => 'https://evil.invalid'])
            ->assertOk()->assertJsonPath('intent', str_repeat('a', 64));
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://control.example.com/api/connect-filament/embed/auth-intents'
            && json_decode($request->body(), true) === $input);
    }

    public function test_resume_requires_a_host_member_and_forwards_a_fresh_signed_actor_without_execution(): void
    {
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        $input = ['session_id' => 'session-1', 'resume_token' => 'opaque-resume', 'message' => 'Training buchen', 'intent' => str_repeat('a', 64)];
        Http::fake(['*' => Http::response(['status' => 'resume_ready', 'message' => $input['message']])]);
        $this->postJson('/tropikal-connect/api/chat/auth-intents/resume', $input)->assertUnauthorized();
        Http::assertNothingSent();
        $this->app->instance(PublicChatActorResolver::class, new class implements PublicChatActorResolver
        {
            public function resolve(Request $request): ?PublicChatActor
            {
                return new PublicChatActor('member', '42');
            }
        });
        $this->postJson('/tropikal-connect/api/chat/auth-intents/resume', [...$input, 'actor_id' => 'forged'])
            ->assertOk()->assertJsonPath('status', 'resume_ready');
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://control.example.com/api/connect-filament/embed/auth-intents/resume'
            && json_decode($request->body(), true) === $input && $request->hasHeader(PublicChatActorPermit::HEADER));
    }

    public function test_missing_old_server_feature_is_preserved(): void
    {
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        $input = ['session_id' => 'session-1', 'resume_token' => 'opaque-resume', 'message' => 'Training buchen'];
        Http::fake(['*' => Http::response(['error' => 'feature_unavailable'], 404)]);
        $this->postJson('/tropikal-connect/api/chat/auth-intents', $input)->assertNotFound()->assertJsonPath('error', 'feature_unavailable');
    }

    public function test_known_domain_conflicts_are_preserved(): void
    {
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        Http::fake(['*' => Http::response(['error' => 'already_trialed'], 409)]);
        $this->postJson('/tropikal-connect/api/chat', ['session_id' => 'session-1', 'message' => 'Trial training'])
            ->assertConflict()->assertJsonPath('error', 'already_trialed');
    }

    public function test_invalid_auth_intent_body_never_reaches_the_control_plane(): void
    {
        $this->connectedInstallation(['embed_status' => Installation::EMBED_ENABLED]);
        Http::fake();
        $this->postJson('/tropikal-connect/api/chat/auth-intents', ['session_id' => 'forged/session',
            'resume_token' => 'opaque-resume', 'message' => 'Training buchen'])->assertUnprocessable();
        Http::assertNothingSent();
    }
}
