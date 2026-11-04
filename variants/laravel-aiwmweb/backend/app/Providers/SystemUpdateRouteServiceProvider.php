<?php

namespace App\Providers;

use App\Http\Controllers\SystemUpdateController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SystemUpdateRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'platform.admin', 'throttle:5,1'])
            ->prefix('admin/system-updates')
            ->group(function (): void {
                Route::get('/', [SystemUpdateController::class, 'index'])->name('platform.updates.index');
                Route::post('/check', [SystemUpdateController::class, 'check'])->name('platform.updates.check');
                Route::post('/install', [SystemUpdateController::class, 'install'])->name('platform.updates.install');
                Route::post('/upload', [SystemUpdateController::class, 'upload'])->name('platform.updates.upload');
                Route::post('/verify', [SystemUpdateController::class, 'verify'])->name('platform.updates.verify');
            });
    }
}
