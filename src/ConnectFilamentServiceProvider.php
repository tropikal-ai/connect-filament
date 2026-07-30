<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;
use TropikalAI\ConnectFilament\Console\InstallCommand;
use TropikalAI\ConnectFilament\Http\Middleware\VerifySignedConnectRequest;
use TropikalAI\ConnectFilament\Models\Installation;
use TropikalAI\ConnectFilament\Observers\SharedResourceObserver;
use TropikalAI\ConnectFilament\Services\CapabilityGrantManager;
use TropikalAI\ConnectFilament\Services\ChangeEventDispatcher;
use TropikalAI\ConnectFilament\Services\EloquentDiscovery;
use TropikalAI\ConnectFilament\Services\ResourceRegistry;

class ConnectFilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/connect-filament.php', 'connect-filament');
        $this->app->singleton(EloquentDiscovery::class);
        $this->app->singleton(ChangeEventDispatcher::class);
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

        $this->observeSharedResources();
    }

    /**
     * Watch only what the owner actually shared.
     *
     * An unshared model gets no observer at all, so it emits nothing rather
     * than emitting and being filtered downstream. Wrapped because this runs on
     * every boot, including before the tables exist: a migration run must not
     * fail because the installation table cannot be queried yet.
     */
    private function observeSharedResources(): void
    {
        try {
            $installation = Installation::query()->first();
            if ($installation === null || ! $installation->isApiReady()) {
                return;
            }

            $registry = $this->app->make(ResourceRegistry::class);
            $manager = $this->app->make(CapabilityGrantManager::class);

            foreach ($manager->sharedSlugs($installation) as $slug) {
                $model = $registry->resource($slug)['model'] ?? null;
                if (is_string($model) && class_exists($model)) {
                    SharedResourceObserver::listen($model, (string) $slug);
                }
            }
        } catch (Throwable) {
            // No database yet, or a half-migrated one. Events simply do not
            // fire until the site is in a state where they could mean anything.
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
