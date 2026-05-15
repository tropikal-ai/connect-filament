<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use TropikalAI\Connect\Domain\OAuth\OAuthState;
use TropikalAI\ConnectFilament\Models\Installation;

final class OAuthSetupTest extends TestCase
{
    public function test_connect_route_requires_authenticated_access(): void
    {
        $this->get(route('connect-filament.oauth.connect'))
            ->assertRedirect('/login');
    }

    public function test_connect_route_starts_pkce_flow(): void
    {
        config()->set('connect-filament.site.url', 'https://example.com');
        config()->set('connect-filament.oauth.redirect_base_url', 'https://cms.example.com');
        Http::fake([
            'https://auth.example.com/oauth/register' => Http::response(['client_id' => 'client_123']),
        ]);

        $response = $this->actingAs($this->createUser())
            ->get(route('connect-filament.oauth.connect'));

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://auth.example.com/oauth/authorize?', $location);
        $this->assertStringContainsString('code_challenge_method=S256', $location);

        $installation = Installation::query()->firstOrFail();
        $plainVerifier = (string) $installation->oauth_code_verifier_encrypted;
        $storedVerifier = (string) DB::table('connect_filament_installations')->whereKey($installation->getKey())->value('oauth_code_verifier_encrypted');
        $this->assertNotSame('', $plainVerifier);
        $this->assertNotSame($plainVerifier, $storedVerifier);
        $this->assertNotNull($installation->oauth_state_hash);
        $this->assertSame('https://example.com', $installation->site_url);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://auth.example.com/oauth/register'
            && $request['client_uri'] === 'https://example.com'
            && $request['redirect_uris'] === ['https://cms.example.com/tropikal-connect/oauth/callback']);
    }

    public function test_callback_rejects_missing_invalid_expired_state_and_wrong_redirect_uri(): void
    {
        $this->get('https://cms.example.com/tropikal-connect/oauth/callback')
            ->assertStatus(400);

        $this->get('https://cms.example.com/tropikal-connect/oauth/callback?state=missing&code=code_123')
            ->assertStatus(400);

        $state = OAuthState::generate();
        Installation::query()->create([
            'site_url' => 'https://cms.example.com',
            'control_plane_url' => 'https://control.example.com',
            'oauth_client_id' => 'client_123',
            'oauth_state_hash' => $state->hash,
            'oauth_code_verifier_encrypted' => 'verifier_123',
            'oauth_state_expires_at' => now()->subMinute(),
        ]);

        $this->get("https://cms.example.com/tropikal-connect/oauth/callback?state={$state->plain}&code=code_123")
            ->assertStatus(400);

        $valid = OAuthState::generate();
        Installation::query()->create([
            'site_url' => 'https://cms.example.com',
            'control_plane_url' => 'https://control.example.com',
            'oauth_client_id' => 'client_456',
            'oauth_state_hash' => $valid->hash,
            'oauth_code_verifier_encrypted' => 'verifier_456',
            'oauth_state_expires_at' => $valid->expiresAt,
        ]);

        $this->get("https://other.example.com/tropikal-connect/oauth/callback?state={$valid->plain}&code=code_123")
            ->assertStatus(400);
    }

    public function test_successful_callback_stores_encrypted_refresh_credential_and_safe_registration_payload(): void
    {
        config()->set('connect-filament.site.url', 'https://example.com');
        config()->set('connect-filament.oauth.redirect_base_url', 'https://cms.example.com');
        config()->set('connect-filament.api.base_url', 'https://api.example.com/api/tropikal-connect');
        config()->set('connect-filament.embed.base_url', 'https://example.com/tropikal-connect');
        $state = OAuthState::generate();
        $installation = Installation::query()->create([
            'site_url' => 'https://example.com',
            'control_plane_url' => 'https://control.example.com',
            'oauth_client_id' => 'client_123',
            'oauth_state_hash' => $state->hash,
            'oauth_code_verifier_encrypted' => 'verifier_123',
            'oauth_state_expires_at' => $state->expiresAt,
        ]);

        Http::fake([
            'https://auth.example.com/oauth/token' => Http::response([
                'access_token' => 'access_123',
                'refresh_token' => 'refresh_123',
                'expires_in' => 300,
            ]),
            'https://control.example.com/api/connect-filament/installations' => Http::response([
                'installation_id' => 'srv_123',
                'server_signing_key' => 'server-signing-key',
                'allowed_resources' => [],
                'resource_permissions' => [],
                'account' => [
                    'id' => 'acct_123',
                    'email' => 'owner@example.com',
                    'workspace_id' => 'workspace_123',
                ],
                'embed' => [
                    'status' => 'not_enabled',
                ],
            ]),
        ]);

        $this->get("https://cms.example.com/tropikal-connect/oauth/callback?state={$state->plain}&code=code_123")
            ->assertRedirect();

        $installation->refresh();
        $this->assertSame(Installation::STATUS_CONNECTED, $installation->status);
        $this->assertSame('refresh_123', $installation->oauth_refresh_token_encrypted);
        $this->assertNull($installation->oauth_state_hash);
        $this->assertNull($installation->oauth_code_verifier_encrypted);

        $row = DB::table('connect_filament_installations')->where('id', $installation->getKey())->first();
        $this->assertNotSame('refresh_123', $row->oauth_refresh_token_encrypted);
        $this->assertNotSame('server-signing-key', $row->server_signing_key_encrypted);

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== 'https://control.example.com/api/connect-filament/installations') {
                return false;
            }

            $payload = $request->data();

            return isset($payload['installation_public_id'], $payload['resources'])
                && $payload['site_url'] === 'https://example.com'
                && $payload['api_base_url'] === 'https://api.example.com/api/tropikal-connect'
                && $payload['embed_base_url'] === 'https://example.com/tropikal-connect'
                && ! isset($payload['access_token'], $payload['refresh_token'], $payload['server_signing_key']);
        });
    }
}
