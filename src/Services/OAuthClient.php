<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use Illuminate\Support\Facades\Http;
use TropikalAI\Connect\Domain\OAuth\AuthorizationRequest;
use TropikalAI\Connect\Domain\OAuth\ClientRegistrationRequest;
use TropikalAI\Connect\Domain\OAuth\OAuthState;
use TropikalAI\Connect\Domain\OAuth\PkcePair;
use TropikalAI\Connect\Domain\OAuth\TokenRequest;
use TropikalAI\Connect\Domain\OAuth\TokenSet;
use TropikalAI\ConnectFilament\Models\Installation;

class OAuthClient
{
    public function beginAuthorization(Installation $installation): string
    {
        $clientId = $this->clientId($installation);
        $state = OAuthState::generate();
        $pkce = PkcePair::generate();

        $installation->forceFill([
            'oauth_client_id' => $clientId,
            'oauth_state_hash' => $state->hash,
            'oauth_code_verifier_encrypted' => $pkce->verifier,
            'oauth_state_expires_at' => $state->expiresAt,
            'site_url' => $this->siteUrl(),
            'control_plane_url' => $this->controlPlaneUrl(),
        ])->save();

        return (new AuthorizationRequest(
            $this->authorizationServerUrl().$this->path('oauth.authorize_path'),
            $clientId,
            $this->redirectUri(),
            $this->scopes(),
            $this->resource(),
            $state->plain,
            $pkce,
        ))->url();
    }

    public function completeAuthorization(Installation $installation, string $code): TokenSet
    {
        $verifier = (string) $installation->oauth_code_verifier_encrypted;
        if ($verifier === '') {
            throw new \RuntimeException('OAuth verifier is missing.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout($this->timeout())
            ->post($this->authorizationServerUrl().$this->path('oauth.token_path'), TokenRequest::authorizationCode(
                $this->clientId($installation),
                $this->redirectUri(),
                $code,
                $verifier,
                $this->resource(),
            ));

        if (! $response->successful()) {
            throw new \RuntimeException('The authorization server rejected the authorization code.');
        }

        return TokenSet::fromArray($response->json() ?? []);
    }

    public function refreshAccessToken(Installation $installation): TokenSet
    {
        $refreshToken = (string) $installation->oauth_refresh_token_encrypted;
        if ($refreshToken === '') {
            throw new \RuntimeException('Connect before syncing.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout($this->timeout())
            ->post($this->authorizationServerUrl().$this->path('oauth.token_path'), TokenRequest::refreshToken(
                $this->clientId($installation),
                $refreshToken,
                $this->resource(),
            ));

        if (! $response->successful()) {
            throw new \RuntimeException('Reconnect before syncing.');
        }

        $tokens = TokenSet::fromArray($response->json() ?? []);
        $installation->forceFill([
            'oauth_refresh_token_encrypted' => $tokens->refreshToken,
            'last_synced_at' => now(),
        ])->save();

        return $tokens;
    }

    public function revokeRefreshToken(Installation $installation): void
    {
        $path = trim((string) config('connect-filament.oauth.revocation_path', ''));
        $refreshToken = (string) $installation->oauth_refresh_token_encrypted;
        if ($path === '' || $refreshToken === '') {
            return;
        }

        Http::asForm()
            ->acceptJson()
            ->timeout($this->timeout())
            ->post($this->authorizationServerUrl().'/'.ltrim($path, '/'), [
                'token' => $refreshToken,
                'token_type_hint' => 'refresh_token',
                'client_id' => $this->clientId($installation),
            ]);
    }

    public function clientId(Installation $installation): string
    {
        $configured = trim((string) config('connect-filament.oauth.client_id', ''));
        if ($configured !== '') {
            return $configured;
        }

        $existing = trim((string) $installation->oauth_client_id);
        if ($existing !== '') {
            return $existing;
        }

        return $this->registerClient($installation);
    }

    public function redirectUri(): string
    {
        return rtrim($this->redirectBaseUrl(), '/').'/'.ltrim((string) config('connect-filament.oauth.redirect_path'), '/');
    }

    public function siteUrl(): string
    {
        $url = trim((string) config('connect-filament.site.url', '')) ?: trim((string) config('app.url', ''));
        if ($url === '') {
            throw new \RuntimeException('The application URL is not configured.');
        }

        return rtrim($url, '/');
    }

    public function controlPlaneUrl(): string
    {
        $url = trim((string) config('connect-filament.control_plane.base_url', ''));
        if ($url === '') {
            throw new \RuntimeException('The control plane URL is not configured.');
        }

        return rtrim($url, '/');
    }

    private function redirectBaseUrl(): string
    {
        $url = trim((string) config('connect-filament.oauth.redirect_base_url', '')) ?: $this->siteUrl();

        return rtrim($url, '/');
    }

    private function registerClient(Installation $installation): string
    {
        $siteUrl = $this->siteUrl();
        $response = Http::acceptJson()
            ->asJson()
            ->timeout($this->timeout())
            ->post($this->authorizationServerUrl().$this->path('oauth.register_path'), (new ClientRegistrationRequest(
                $this->clientName($siteUrl),
                [$this->redirectUri()],
                $this->scopes(),
                $this->resource(),
                $siteUrl,
                trim((string) config('connect-filament.oauth.software_id', '')),
            ))->toArray());

        if (! $response->successful()) {
            throw new \RuntimeException('The authorization server rejected OAuth client registration.');
        }

        $payload = $response->json();
        $clientId = is_array($payload) ? trim((string) ($payload['client_id'] ?? '')) : '';
        if ($clientId === '') {
            throw new \RuntimeException('The authorization server returned an invalid client registration response.');
        }

        $installation->forceFill([
            'oauth_client_id' => $clientId,
            'site_url' => $siteUrl,
            'control_plane_url' => $this->controlPlaneUrl(),
        ])->save();

        return $clientId;
    }

    private function authorizationServerUrl(): string
    {
        $url = trim((string) config('connect-filament.oauth.authorization_server_url', ''));
        if ($url === '') {
            throw new \RuntimeException('The authorization server URL is not configured.');
        }

        return rtrim($url, '/');
    }

    private function scopes(): string
    {
        $scopes = trim((string) config('connect-filament.oauth.scopes', ''));
        if ($scopes === '') {
            throw new \RuntimeException('OAuth scopes are not configured.');
        }

        return $scopes;
    }

    private function resource(): string
    {
        return trim((string) config('connect-filament.oauth.resource', ''));
    }

    private function clientName(string $siteUrl): string
    {
        $configured = trim((string) config('connect-filament.oauth.client_name', ''));
        if ($configured !== '') {
            return $configured;
        }

        $host = parse_url($siteUrl, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? "TROPIKAL Connect for {$host}" : 'TROPIKAL Connect';
    }

    private function path(string $key): string
    {
        return '/'.ltrim((string) config("connect-filament.{$key}", ''), '/');
    }

    private function timeout(): int
    {
        return max(1, (int) config('connect-filament.oauth.timeout_seconds', 10));
    }
}
