<?php

namespace App\Providers;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CanonicalSettingsRouteServiceProvider extends ServiceProvider
{
    public const OPERATION_ID = 'AIMW-CONT-3FD89CE024';

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/settings', [CanonicalWorkspaceRouteController::class, 'show'])
            ->defaults('workspace_permissions', 'tenant.view')
            ->defaults('canonical_operation_id', self::OPERATION_ID)
            ->name('canonical.workspace.settings');
    }
}
