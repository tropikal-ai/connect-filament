<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TropikalAI\ConnectFilament\Http\Controllers\EmbedController;

$prefix = trim((string) config('connect-filament.route_prefix', 'tropikal-connect'), '/');

Route::prefix($prefix)
    ->name('connect-filament.')
    ->group(function (): void {
        if ((bool) config('connect-filament.embed.enabled', true)) {
            Route::get('/api/chat/info', [EmbedController::class, 'chatInfo'])
                ->name('embed.chat.info');
            Route::post('/api/chat', [EmbedController::class, 'chat'])
                ->name('embed.chat');
        }
    });
