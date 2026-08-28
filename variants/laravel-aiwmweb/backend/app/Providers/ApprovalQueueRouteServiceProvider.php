<?php

namespace App\Providers;

use App\Http\Controllers\ApprovalQueueReadController;
use App\Http\Controllers\CanonicalWorkspaceRouteController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ApprovalQueueRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/api/tenants/{tenant}/approvals', [ApprovalQueueReadController::class, 'index'])
            ->name('canonical.api.approvals.load');

        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/approvals', [CanonicalWorkspaceRouteController::class, 'redirect'])
            ->defaults('workspace_permissions', 'tenant.view,approvals.view')
            ->defaults('workspace_target', '/module/approvals')
            ->name('canonical.alias.approvals');
    }
}
