<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use TropikalAI\Connect\Domain\Security\SensitiveData;
use TropikalAI\ConnectFilament\Services\UrlPolicy;

/**
 * @property int $id
 * @property string|null $public_id
 * @property string|null $status
 * @property string|null $account_id
 * @property string|null $account_email
 * @property string|null $workspace_id
 * @property string|null $site_url
 * @property string|null $control_plane_url
 * @property string|null $oauth_client_id
 * @property string|null $oauth_refresh_token_encrypted
 * @property string|null $oauth_state_hash
 * @property string|null $oauth_code_verifier_encrypted
 * @property Carbon|null $oauth_state_expires_at
 * @property Carbon|null $connected_at
 * @property string|null $control_plane_installation_id
 * @property string|null $server_signing_key_encrypted
 * @property array<int, string>|null $allowed_resources
 * @property array<string, array<int, string>>|null $resource_permissions
 * @property string|null $embed_status
 * @property string|null $embed_public_id
 * @property string|null $embed_display_name
 * @property Carbon|null $embed_enabled_at
 * @property Carbon|null $last_synced_at
 * @property array<string, mixed>|null $settings
 */
class Installation extends Model
{
    public const STATUS_NOT_CONNECTED = 'not_connected';

    public const STATUS_PENDING_REGISTRATION = 'pending_registration';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_ERROR = 'error';

    public const EMBED_NOT_ENABLED = 'not_enabled';

    public const EMBED_ENABLED = 'enabled';

    protected $table = 'connect_filament_installations';

    protected $fillable = [
        'public_id',
        'status',
        'account_id',
        'account_email',
        'workspace_id',
        'site_url',
        'control_plane_url',
        'oauth_client_id',
        'oauth_refresh_token_encrypted',
        'oauth_state_hash',
        'oauth_code_verifier_encrypted',
        'oauth_state_expires_at',
        'connected_at',
        'control_plane_installation_id',
        'server_signing_key_encrypted',
        'allowed_resources',
        'resource_permissions',
        'embed_status',
        'embed_public_id',
        'embed_display_name',
        'embed_enabled_at',
        'last_synced_at',
        'settings',
    ];

    protected $casts = [
        'oauth_refresh_token_encrypted' => 'encrypted',
        'oauth_code_verifier_encrypted' => 'encrypted',
        'server_signing_key_encrypted' => 'encrypted',
        'oauth_state_expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'embed_enabled_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'allowed_resources' => 'array',
        'resource_permissions' => 'array',
        'settings' => 'array',
    ];

    protected $attributes = [
        'status' => self::STATUS_NOT_CONNECTED,
        'embed_status' => self::EMBED_NOT_ENABLED,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $installation): void {
            if (! $installation->public_id) {
                $installation->public_id = 'cfi_'.Str::random(32);
            }
        });
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    public function isApiReady(): bool
    {
        return $this->isConnected()
            && filled($this->public_id)
            && filled($this->server_signing_key_encrypted);
    }

    public function safeStatus(): array
    {
        $payload = [
            'status' => $this->status ?: self::STATUS_NOT_CONNECTED,
            'account' => [
                'email' => $this->account_email,
            ],
            'website' => [
                'url' => $this->site_url,
                'detail_url' => $this->websiteDetailUrl(),
            ],
            'site' => [
                'url' => $this->site_url,
            ],
            'resources' => [
                'allowed' => array_values($this->allowed_resources ?? []),
                'count' => count($this->allowed_resources ?? []),
                'last_synced_at' => $this->last_synced_at?->toISOString(),
            ],
            'embed' => [
                'status' => $this->embed_status ?: self::EMBED_NOT_ENABLED,
                'public_id' => $this->embed_public_id,
                'display_name' => $this->embed_display_name,
                'snippet' => self::embedSnippet(),
                'enabled_at' => $this->embed_enabled_at?->toISOString(),
            ],
        ];

        SensitiveData::assertPublicPayload($payload);

        return $payload;
    }

    public function websiteDetailUrl(): ?string
    {
        $settings = is_array($this->settings) ? $this->settings : [];
        $website = is_array($settings['website'] ?? null) ? $settings['website'] : [];
        $url = $website['detail_url'] ?? null;

        return UrlPolicy::publicUrlOrNull($url);
    }

    public static function embedSnippet(?string $prefix = null): string
    {
        $prefix = trim($prefix ?: (string) config('connect-filament.embed.prefix', 'tropikal-connect'), '/');
        if ($prefix === '') {
            throw new \InvalidArgumentException('The embed route prefix cannot be empty.');
        }

        return sprintf('<script async src="/%s/embed/widget.js"></script>', $prefix);
    }

    public function markDisconnected(): void
    {
        $this->forceFill([
            'status' => self::STATUS_NOT_CONNECTED,
            'account_id' => null,
            'account_email' => null,
            'workspace_id' => null,
            'oauth_refresh_token_encrypted' => null,
            'oauth_state_hash' => null,
            'oauth_code_verifier_encrypted' => null,
            'oauth_state_expires_at' => null,
            'connected_at' => null,
            'control_plane_installation_id' => null,
            'server_signing_key_encrypted' => null,
            'allowed_resources' => [],
            'resource_permissions' => [],
            'embed_status' => self::EMBED_NOT_ENABLED,
            'embed_public_id' => null,
            'embed_display_name' => null,
            'embed_enabled_at' => null,
            'last_synced_at' => now(),
            'settings' => [],
        ])->save();
    }
}
