<?php

namespace App\Providers;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Http\Controllers\SystemHealthReadController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SystemHealthRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->prefix('tenants/{tenant}')
            ->controller(CanonicalWorkspaceRouteController::class)
            ->group(function (): void {
                Route::get('/system-health', 'show')
                    ->defaults('workspace_permissions', 'tenant.view,diagnostics.view')
                    ->name('tenant.system-health');
            });

        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/api/tenants/{tenant}/system-health', SystemHealthReadController::class)
            ->name('tenant.system-health.snapshot');
    }
}
