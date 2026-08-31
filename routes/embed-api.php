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
            Route::get('/api/chat/session', [EmbedController::class, 'chatSession'])
                ->name('embed.chat.session');
            Route::get('/api/chat/history', [EmbedController::class, 'history'])
                ->name('embed.chat.history.list');
            Route::get('/api/chat/history/{conversation}', [EmbedController::class, 'historyRead'])
                ->whereUuid('conversation')
                ->name('embed.chat.history.read');
            Route::delete('/api/chat/history/clear', [EmbedController::class, 'historyClear'])
                ->name('embed.chat.history.clear');
            Route::delete('/api/chat/history/{conversation}', [EmbedController::class, 'historyDelete'])
                ->whereUuid('conversation')
                ->name('embed.chat.history.delete');
            Route::post('/api/human-verification/challenge', [EmbedController::class, 'humanVerificationChallenge'])
                ->name('embed.human-verification.challenge');
            Route::post('/api/chat/actions/{action}/confirm', [EmbedController::class, 'actionConfirm'])
                ->whereUuid('action')
                ->name('embed.chat.actions.confirm');
            Route::post('/api/chat/actions/{action}/cancel', [EmbedController::class, 'actionCancel'])
                ->whereUuid('action')
                ->name('embed.chat.actions.cancel');
        }
    });
