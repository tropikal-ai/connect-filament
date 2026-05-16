<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use TropikalAI\ConnectFilament\Console\InstallCommand;
use TropikalAI\ConnectFilament\Http\Middleware\VerifySignedConnectRequest;
use TropikalAI\ConnectFilament\Services\EloquentDiscovery;
use TropikalAI\ConnectFilament\Services\ResourceRegistry;

class ConnectFilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/connect-filament.php', 'connect-filament');
        $this->app->singleton(EloquentDiscovery::class);
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
        $this->routes();
        $this->publishes([
            __DIR__.'/../config/connect-filament.php' => config_path('connect-filament.php'),
        ], 'connect-filament-config');
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'connect-filament-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
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
