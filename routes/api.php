<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TropikalAI\ConnectFilament\Http\Controllers\ResourceController;

Route::prefix('/installations/{installationId}')
    ->where(['installationId' => 'cfi_[A-Za-z0-9]+'])
    ->middleware('connect-filament.signed')
    ->group(function (): void {
        Route::get('/schema', [ResourceController::class, 'schema'])
            ->name('connect-filament.api.schema');

        Route::get('/resources/{resource}', [ResourceController::class, 'index'])
            ->name('connect-filament.api.resources.index');
        Route::post('/resources/{resource}', [ResourceController::class, 'store'])
            ->name('connect-filament.api.resources.store');
        Route::get('/resources/{resource}/{id}', [ResourceController::class, 'show'])
            ->name('connect-filament.api.resources.show');
        Route::match(['put', 'patch'], '/resources/{resource}/{id}', [ResourceController::class, 'update'])
            ->name('connect-filament.api.resources.update');
        Route::post('/resources/{resource}/{id}/actions/{action}', [ResourceController::class, 'action'])
            ->name('connect-filament.api.resources.action');
    });
