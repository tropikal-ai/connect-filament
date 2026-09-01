<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Console;

use Illuminate\Console\Command;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Services\ControlPlaneClient;

final class SyncCommand extends Command
{
    protected $signature = 'connect-filament:sync';

    protected $description = 'Synchronize connected Website capabilities and embed status with TROPIKAL.';

    public function handle(ControlPlaneClient $controlPlane): int
    {
        $installations = Installation::query()
            ->where('status', Installation::STATUS_CONNECTED)
            ->orderBy('id')
            ->get();

        if ($installations->isEmpty()) {
            $this->components->info('TROPIKAL Connect is not configured; nothing to synchronize.');

            return self::SUCCESS;
        }

        foreach ($installations as $installation) {
            try {
                $controlPlane->syncCapabilities($installation);
                $controlPlane->syncEmbedStatus($installation->refresh());
            } catch (\Throwable $exception) {
                $this->components->error(sprintf(
                    'TROPIKAL Connect synchronization failed for %s: %s',
                    $installation->public_id,
                    $exception->getMessage(),
                ));

                return self::FAILURE;
            }
        }

        $this->components->info(sprintf(
            'Synchronized %d TROPIKAL Connect installation(s).',
            $installations->count(),
        ));

        return self::SUCCESS;
    }
}
