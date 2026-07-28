<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Services;

use TropikalAI\Connect\Application\PublicChannels\Ports\InstallationCredentialsProvider;
use TropikalAI\Connect\Domain\PublicChannels\InstallationCredentials;
use TropikalAI\ConnectFilament\Models\Installation;

final class LaravelInstallationCredentialsProvider implements InstallationCredentialsProvider
{
    public function current(): ?InstallationCredentials
    {
        $installation = Installation::query()
            ->where('status', Installation::STATUS_CONNECTED)
            ->latest('updated_at')
            ->first();
        if (! $installation?->isApiReady()) {
            return null;
        }

        return new InstallationCredentials(
            (string) $installation->public_id,
            (string) $installation->server_signing_key_encrypted,
            (string) $installation->site_url,
            (string) $installation->control_plane_url,
        );
    }
}
