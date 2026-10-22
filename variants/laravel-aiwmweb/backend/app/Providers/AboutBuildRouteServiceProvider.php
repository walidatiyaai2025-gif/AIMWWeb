<?php

namespace App\Providers;

use App\Http\Controllers\AboutBuildReadController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AboutBuildRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->prefix('tenants/{tenant}')
            ->group(function (): void {
                Route::get('/about-build', AboutBuildReadController::class)->name('tenant.about-build');
                Route::get('/release-notes', AboutBuildReadController::class)->name('tenant.release-notes');
            });
    }
}
