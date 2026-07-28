<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Filament\Resources\InstallationResource\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use TropikalAI\Connect\Application\PublicChannels\ChangePublicComponentPlacement;
use TropikalAI\Connect\Domain\PublicChannels\PublicComponentType;
use TropikalAI\ConnectFilament\Filament\Resources\InstallationResource;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Services\CapabilityGrantManager;
use TropikalAI\ConnectFilament\Services\ControlPlaneClient;
use TropikalAI\ConnectFilament\Services\LaravelPublicComponentSettingsStore;
use TropikalAI\ConnectFilament\Services\ResourceRegistry;

class Dashboard extends Page
{
    protected static string $resource = InstallationResource::class;

    public ?Installation $installation = null;

    public array $capabilityGrants = [];

    public bool $chatAutoInject = true;

    public function getView(): string
    {
        return 'connect-filament::filament.resources.installation-resource.pages.dashboard';
    }

    public function mount(): void
    {
        $this->installation = Installation::query()->first();
        $this->capabilityGrants = $this->installation
            ? app(CapabilityGrantManager::class)->grants($this->installation)
            : [];
        $this->chatAutoInject = app(LaravelPublicComponentSettingsStore::class)
            ->get(PublicComponentType::Chat)->autoInject;
    }

    public function sync(): void
    {
        if (! $this->installation?->isConnected()) {
            return;
        }

        app(ControlPlaneClient::class)->syncCapabilities($this->installation);
        app(ControlPlaneClient::class)->syncEmbedStatus($this->installation->refresh());
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

    public function setCapabilityGrant(string $slug, string $grant, mixed $enabled): void
    {
        if (! $this->installation) {
            return;
        }

        $this->installation = app(CapabilityGrantManager::class)->set(
            $this->installation,
            $slug,
            $grant,
            filter_var($enabled, FILTER_VALIDATE_BOOL),
        );

        if ($this->installation->isConnected()) {
            app(ControlPlaneClient::class)->syncCapabilities($this->installation);
        }

        $this->mount();

        Notification::make()
            ->title('Capabilities updated')
            ->success()
            ->send();
    }

    public function setChatPlacement(mixed $enabled): void
    {
        if (! $this->installation?->isConnected()) {
            return;
        }
        $value = filter_var($enabled, FILTER_VALIDATE_BOOL);
        (new ChangePublicComponentPlacement(app(LaravelPublicComponentSettingsStore::class)))
            ->handle(PublicComponentType::Chat, $value);
        $this->chatAutoInject = $value;

        Notification::make()
            ->title('Website chat placement updated')
            ->success()
            ->send();
    }

    public function status(): array
    {
        return $this->installation?->safeStatus() ?? ['status' => Installation::STATUS_NOT_CONNECTED];
    }

    public function discoveredResources(): array
    {
        return app(ResourceRegistry::class)->all();
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
            Action::make('openWebsite')
                ->label('Open website')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (): ?string => $this->installation?->canonicalSiteUrl())
                ->openUrlInNewTab()
                ->visible(fn (): bool => (bool) $this->installation?->isConnected() && filled($this->installation?->canonicalSiteUrl())),
            Action::make('configure')
                ->label('Configure in Tropikal')
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
