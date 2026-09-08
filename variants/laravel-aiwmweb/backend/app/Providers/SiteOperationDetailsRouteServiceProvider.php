<?php

namespace App\Providers;

use App\Http\Controllers\SiteOperationDetailsReadController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SiteOperationDetailsRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Canonical source route: /site-operations/{OperationId:guid}
        // Keep the path segment unconstrained at the router layer so malformed
        // operation IDs reach the controller's UUID guard and fail closed with
        // a 404 instead of falling through to the tenant SPA catch-all route.
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/site-operations/{operationId}', SiteOperationDetailsReadController::class)
            ->defaults('workspace_permissions', 'execution.view')
            ->defaults('canonical_operation_id', 'AIMW-AI-3CDB30A4C2')
            ->name('canonical.workspace.site-operation-details');

        // Canonical source alias: /operations/sites/{OperationId:guid}
        // This alias is a distinct canonical parity operation, while sharing
        // the same read-only tenant-authorized controller and rendered view.
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/operations/sites/{operationId}', SiteOperationDetailsReadController::class)
            ->defaults('workspace_permissions', 'execution.view')
            ->defaults('canonical_operation_id', 'AIMW-AI-E5D089844A')
            ->name('canonical.alias.operations-site-details');
    }
}
