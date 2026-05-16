<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'connect-filament:install';

    protected $description = 'Publish TROPIKAL Connect configuration and show the Filament plugin registration snippet.';

    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--tag' => 'connect-filament-config',
            '--force' => false,
        ]);

        $this->line('use TropikalAI\\ConnectFilament\\ConnectFilamentPlugin;');
        $this->line('');
        $this->line('$panel->plugin(ConnectFilamentPlugin::make());');

        return self::SUCCESS;
    }
}
