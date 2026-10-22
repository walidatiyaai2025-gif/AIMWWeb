<?php

namespace App\Providers;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ContentPlannerRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/content-planner', [CanonicalWorkspaceRouteController::class, 'show'])
            ->defaults('workspace_permissions', 'tenant.view,content.view')
            ->defaults('canonical_operation_id', 'AIMW-BILL-69825FEAD5')
            ->name('canonical.workspace.content-planner');
    }
}
