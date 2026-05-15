<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TropikalAI\ConnectFilament\Http\Controllers\EmbedController;
use TropikalAI\ConnectFilament\Http\Controllers\OAuthSetupController;

$prefix = trim((string) config('connect-filament.route_prefix', 'tropikal-connect'), '/');

Route::prefix($prefix)
    ->name('connect-filament.')
    ->group(function (): void {
        Route::get('/oauth/connect', [OAuthSetupController::class, 'connect'])
            ->middleware(config('connect-filament.setup.connect_middleware', ['auth']))
            ->name('oauth.connect');

        Route::get('/oauth/callback', [OAuthSetupController::class, 'callback'])
            ->name('oauth.callback');

        if ((bool) config('connect-filament.embed.enabled', true)) {
            Route::get('/embed/widget.js', [EmbedController::class, 'widget'])
                ->name('embed.widget');
            Route::get('/embed/info', [EmbedController::class, 'info'])
                ->name('embed.info');
            Route::get('/embed/{asset}', [EmbedController::class, 'asset'])
                ->where('asset', 'chat-widget\.js|iframe\.html|iframe\.js|iframe\.css|markdown\.js')
                ->name('embed.asset');
            Route::get('/api/chat/info', [EmbedController::class, 'chatInfo'])
                ->name('embed.chat.info');
            Route::post('/api/chat', [EmbedController::class, 'chat'])
                ->name('embed.chat');
        }
    });
