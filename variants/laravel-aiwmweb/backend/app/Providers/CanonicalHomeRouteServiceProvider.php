<?php

namespace App\Providers;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Http\Controllers\DashboardReadController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CanonicalHomeRouteServiceProvider extends ServiceProvider
{
    public const OPERATION_ID = 'AIMW-CONT-519B93F503';

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}', [CanonicalWorkspaceRouteController::class, 'show'])
            ->defaults('workspace_permissions', 'tenant.view')
            ->defaults('canonical_operation_id', self::OPERATION_ID)
            ->name('canonical.workspace.home');

        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/route-api/dashboard', DashboardReadController::class)
            ->defaults('canonical_operation_id', self::OPERATION_ID)
            ->name('canonical.api.dashboard');
    }
}
