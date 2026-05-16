<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use TropikalAI\ConnectFilament\Filament\Resources\InstallationResource;

class ConnectFilamentPlugin implements Plugin
{
    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return 'tropikal-connect-filament';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            InstallationResource::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}
