<?php

namespace App\Providers;

use App\Http\Controllers\OperationsMaintenanceReadController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class OperationsMaintenanceRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/operations/maintenance', OperationsMaintenanceReadController::class)
            ->defaults('workspace_permissions', 'execution.view')
            ->defaults('canonical_operation_id', 'AIMW-AI-6EF2330C99')
            ->name('canonical.workspace.operations-maintenance');
    }
}
