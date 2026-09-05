<?php

namespace App\Providers;

use App\Http\Controllers\SiteOperationsMaintenanceReadController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SiteOperationsMaintenanceRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/site-operations/maintenance', SiteOperationsMaintenanceReadController::class)
            ->defaults('workspace_permissions', 'execution.view')
            ->defaults('canonical_operation_id', 'AIMW-AI-959B247B1D')
            ->name('canonical.workspace.site-operations-maintenance');
    }
}
