<?php

namespace App\Providers;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SitesConnectRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/sites/connect', [CanonicalWorkspaceRouteController::class, 'show'])
            ->defaults('workspace_permissions', 'tenant.view,sites.manage')
            ->defaults('canonical_operation_id', 'AIMW-SITE-E3EA44AD3F')
            ->name('canonical.site.connect');
    }
}
