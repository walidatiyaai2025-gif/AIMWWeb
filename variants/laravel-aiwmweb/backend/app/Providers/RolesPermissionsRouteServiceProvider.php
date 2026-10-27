<?php

namespace App\Providers;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class RolesPermissionsRouteServiceProvider extends ServiceProvider
{
    public const OPERATION_ID = 'AIMW-CONT-9CF12067E6';

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/admin/roles-permissions', [CanonicalWorkspaceRouteController::class, 'show'])
            ->defaults('workspace_permissions', 'tenant.view,roles.view')
            ->defaults('canonical_operation_id', self::OPERATION_ID)
            ->name('canonical.workspace.roles-permissions');
    }
}
