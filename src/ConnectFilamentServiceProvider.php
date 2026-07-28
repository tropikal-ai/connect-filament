<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use TropikalAI\Connect\Application\PublicChannels\Ports\PublicComponentSettingsStore;
use TropikalAI\Connect\Application\PublicChannels\PublicChannelsConfig;
use TropikalAI\Connect\Application\PublicChannels\PublicChannelsService;
use TropikalAI\Connect\Infrastructure\PublicChannels\ControlPlaneHumanVerification;
use TropikalAI\Connect\Infrastructure\PublicChannels\SignedControlPlaneGateway;
use TropikalAI\ConnectFilament\Console\InjectPublicComponentsCommand;
use TropikalAI\ConnectFilament\Console\InstallCommand;
use TropikalAI\ConnectFilament\Http\Middleware\InjectPublicComponents;
use TropikalAI\ConnectFilament\Http\Middleware\VerifySignedConnectRequest;
use TropikalAI\ConnectFilament\Services\EloquentDiscovery;
use TropikalAI\ConnectFilament\Services\LaravelContractCache;
use TropikalAI\ConnectFilament\Services\LaravelInstallationCredentialsProvider;
use TropikalAI\ConnectFilament\Services\LaravelPublicChannelHttpTransport;
use TropikalAI\ConnectFilament\Services\LaravelPublicChannelLogger;
use TropikalAI\ConnectFilament\Services\LaravelPublicChannelRateLimiter;
use TropikalAI\ConnectFilament\Services\LaravelPublicComponentSettingsStore;
use TropikalAI\ConnectFilament\Services\ResourceRegistry;

class ConnectFilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/connect-filament.php', 'connect-filament');
        $this->app->singleton(EloquentDiscovery::class);
        $this->app->singleton(PublicComponentSettingsStore::class, LaravelPublicComponentSettingsStore::class);
        $this->app->singleton(SignedControlPlaneGateway::class, fn (): SignedControlPlaneGateway => new SignedControlPlaneGateway(
            new LaravelInstallationCredentialsProvider,
            new LaravelPublicChannelHttpTransport,
        ));
        $this->app->singleton(PublicChannelsService::class, function ($app): PublicChannelsService {
            $gateway = $app->make(SignedControlPlaneGateway::class);

            return new PublicChannelsService(
                $gateway,
                new LaravelContractCache,
                new ControlPlaneHumanVerification($gateway),
                new LaravelPublicChannelRateLimiter,
                new LaravelPublicChannelLogger,
                new PublicChannelsConfig,
                $app->make(PublicComponentSettingsStore::class),
            );
        });
        $this->app->singleton(ResourceRegistry::class, fn ($app): ResourceRegistry => new ResourceRegistry(
            $app['config']->get('connect-filament.resources', []),
            $app->make(EloquentDiscovery::class),
        ));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'connect-filament');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->app['router']->aliasMiddleware('connect-filament.signed', VerifySignedConnectRequest::class);
        if ((bool) config('connect-filament.public_components.middleware.enabled', true)) {
            $this->app['router']->pushMiddlewareToGroup('web', InjectPublicComponents::class);
        }
        $this->routes();
        $this->publishes([
            __DIR__.'/../config/connect-filament.php' => config_path('connect-filament.php'),
        ], 'connect-filament-config');
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'connect-filament-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, InjectPublicComponentsCommand::class]);
        }
    }

    private function routes(): void
    {
        Route::middleware('web')->group(__DIR__.'/../routes/web.php');
        Route::middleware('api')->group(__DIR__.'/../routes/embed-api.php');
        Route::prefix('api/'.trim((string) config('connect-filament.api.prefix', 'tropikal-connect'), '/'))
            ->middleware('api')
            ->group(__DIR__.'/../routes/api.php');
    }
}
