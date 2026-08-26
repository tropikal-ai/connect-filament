<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Filament\Resources;

use BackedEnum;
use Filament\Panel;
use Filament\Resources\Resource;
use TropikalAI\ConnectFilament\Filament\Resources\InstallationResource\Pages\Dashboard;
use TropikalAI\ConnectFilament\Models\Installation;

class InstallationResource extends Resource
{
    protected static ?string $model = Installation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-link';

    public static function getNavigationLabel(): string
    {
        return (string) config('connect-filament.filament.navigation_label', 'TROPIKAL Connect');
    }

    public static function getNavigationGroup(): ?string
    {
        $group = config('connect-filament.filament.navigation_group', 'Integrations');

        return is_string($group) && $group !== '' ? $group : null;
    }

    public static function getNavigationSort(): ?int
    {
        return (int) config('connect-filament.filament.navigation_sort', 90);
    }

    public static function getModelLabel(): string
    {
        return (string) config('connect-filament.filament.label', 'TROPIKAL Connect');
    }

    public static function getPluralModelLabel(): string
    {
        return static::getModelLabel();
    }

    public static function getSlug(?Panel $panel = null): string
    {
        return trim((string) config('connect-filament.filament.slug', 'tropikal-connect'), '/');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Dashboard::route('/'),
        ];
    }
}
