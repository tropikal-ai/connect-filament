<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Filament\Resources\InstallationResource\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use TropikalAI\ConnectFilament\Filament\Resources\InstallationResource;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Services\ControlPlaneClient;

class Dashboard extends Page
{
    protected static string $resource = InstallationResource::class;

    protected static string $view = 'connect-filament::filament.resources.installation-resource.pages.dashboard';

    public ?Installation $installation = null;

    public function mount(): void
    {
        $this->installation = Installation::query()->first();
    }

    public function sync(): void
    {
        if (! $this->installation?->isConnected()) {
            return;
        }

        app(ControlPlaneClient::class)->syncEmbedStatus($this->installation);
        $this->mount();

        Notification::make()
            ->title('Status updated')
            ->success()
            ->send();
    }

    public function disconnect(): void
    {
        if (! $this->installation?->isConnected()) {
            return;
        }

        app(ControlPlaneClient::class)->disconnectInstallation($this->installation);
        $this->mount();

        Notification::make()
            ->title('Disconnected')
            ->success()
            ->send();
    }

    public function status(): array
    {
        return $this->installation?->safeStatus() ?? ['status' => Installation::STATUS_NOT_CONNECTED];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connect')
                ->label('Connect')
                ->url(route('connect-filament.oauth.connect'))
                ->visible(fn (): bool => ! $this->installation?->isConnected()),
            Action::make('sync')
                ->label('Refresh status')
                ->action('sync')
                ->visible(fn (): bool => (bool) $this->installation?->isConnected()),
            Action::make('website')
                ->label('Website detail')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (): ?string => $this->installation?->websiteDetailUrl())
                ->openUrlInNewTab()
                ->visible(fn (): bool => (bool) $this->installation?->isConnected() && filled($this->installation?->websiteDetailUrl())),
            Action::make('disconnect')
                ->label('Disconnect')
                ->requiresConfirmation()
                ->color('danger')
                ->action('disconnect')
                ->visible(fn (): bool => (bool) $this->installation?->isConnected()),
        ];
    }
}
