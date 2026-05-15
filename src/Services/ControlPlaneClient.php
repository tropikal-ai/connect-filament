<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use Illuminate\Support\Facades\Http;
use TropikalAI\Connect\Domain\OAuth\TokenSet;
use TropikalAI\Connect\Domain\Security\SensitiveData;
use TropikalAI\ConnectFilament\Models\Installation;

class ControlPlaneClient
{
    public function __construct(
        private readonly OAuthClient $oauth,
        private readonly ResourceRegistry $registry,
    ) {}

    public function registerInstallation(Installation $installation, TokenSet $tokens): array
    {
        $payload = [
            'installation_public_id' => $installation->public_id,
            'site_url' => $installation->site_url,
            'api_base_url' => $this->apiBaseUrl($installation),
            'embed_base_url' => $this->embedBaseUrl($installation),
            'resources' => $this->declaredResourceSchema(),
        ];
        SensitiveData::assertPublicPayload($payload);

        $body = $this->post($installation, $this->path('register_path'), $payload, $tokens->accessToken);
        $this->applyRegistrationResponse($installation, $body);

        return $body;
    }

    public function syncEmbedStatus(Installation $installation): array
    {
        $tokens = $this->oauth->refreshAccessToken($installation);
        $body = $this->post($installation, $this->path('embed_status_path'), [
            'installation_public_id' => $installation->public_id,
            'site_url' => $installation->site_url,
        ], $tokens->accessToken);

        $embed = is_array($body['embed'] ?? null) ? $body['embed'] : $body;
        $installation->forceFill([
            'embed_status' => (string) ($embed['status'] ?? Installation::EMBED_NOT_ENABLED),
            'embed_public_id' => $embed['public_id'] ?? null,
            'embed_display_name' => $embed['display_name'] ?? null,
            'embed_enabled_at' => (($embed['status'] ?? '') === Installation::EMBED_ENABLED) ? now() : $installation->embed_enabled_at,
            'last_synced_at' => now(),
        ])->save();

        return $body;
    }

    public function disconnectInstallation(Installation $installation): array
    {
        $tokens = $this->oauth->refreshAccessToken($installation);
        $body = $this->post($installation, $this->path('disconnect_path'), [
            'installation_public_id' => $installation->public_id,
            'site_url' => $installation->site_url,
        ], $tokens->accessToken);

        $this->oauth->revokeRefreshToken($installation);
        $installation->markDisconnected();

        return $body;
    }

    private function post(Installation $installation, string $path, array $payload, string $accessToken): array
    {
        $response = Http::acceptJson()
            ->asJson()
            ->timeout($this->timeout())
            ->withToken($accessToken)
            ->post(rtrim((string) $installation->control_plane_url, '/').$path, $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('The control plane rejected the connect request.');
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new \RuntimeException('The control plane returned an invalid response.');
        }

        return $body;
    }

    private function applyRegistrationResponse(Installation $installation, array $body): void
    {
        $serverKey = (string) ($body['server_signing_key'] ?? '');
        if ($serverKey === '') {
            throw new \RuntimeException('The control plane response did not include server credentials.');
        }

        $account = is_array($body['account'] ?? null) ? $body['account'] : [];
        $embed = is_array($body['embed'] ?? null) ? $body['embed'] : [];

        $installation->forceFill([
            'status' => Installation::STATUS_CONNECTED,
            'account_id' => (string) ($account['id'] ?? ''),
            'account_email' => (string) ($account['email'] ?? ''),
            'workspace_id' => (string) ($account['workspace_id'] ?? ''),
            'control_plane_installation_id' => (string) ($body['installation_id'] ?? ''),
            'server_signing_key_encrypted' => $serverKey,
            'allowed_resources' => array_values($body['allowed_resources'] ?? []),
            'resource_permissions' => $body['resource_permissions'] ?? [],
            'connected_at' => now(),
            'embed_status' => (string) ($embed['status'] ?? Installation::EMBED_NOT_ENABLED),
            'embed_public_id' => $embed['public_id'] ?? null,
            'embed_display_name' => $embed['display_name'] ?? null,
            'embed_enabled_at' => (($embed['status'] ?? '') === Installation::EMBED_ENABLED) ? now() : null,
            'last_synced_at' => now(),
            'settings' => [
                'resource_count' => count($body['allowed_resources'] ?? []),
            ],
        ])->save();
    }

    private function declaredResourceSchema(): array
    {
        $schema = [];
        foreach ($this->registry->all() as $slug => $resource) {
            $schema[$slug] = [
                'label' => $resource['label'] ?? $slug,
                'identifier' => $resource['identifier'] ?? 'id',
                'fields' => $resource['fields'] ?? [],
                'searchable' => array_values($resource['searchable'] ?? []),
                'filterable' => array_values($resource['filterable'] ?? []),
                'actions' => array_map(
                    fn (array $action): array => [
                        'label' => $action['label'] ?? '',
                        'description' => $action['description'] ?? '',
                    ],
                    $resource['actions'] ?? [],
                ),
            ];
        }

        SensitiveData::assertPublicPayload($schema);

        return $schema;
    }

    private function path(string $key): string
    {
        return '/'.ltrim((string) config("connect-filament.control_plane.{$key}", ''), '/');
    }

    private function apiBaseUrl(Installation $installation): string
    {
        $configured = trim((string) config('connect-filament.api.base_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim((string) $installation->site_url, '/').'/api/'.trim((string) config('connect-filament.api.prefix', 'tropikal-connect'), '/');
    }

    private function embedBaseUrl(Installation $installation): string
    {
        $configured = trim((string) config('connect-filament.embed.base_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim((string) $installation->site_url, '/').'/'.trim((string) config('connect-filament.embed.prefix', 'tropikal-connect'), '/');
    }

    private function timeout(): int
    {
        return max(1, (int) config('connect-filament.control_plane.timeout_seconds', 20));
    }
}
