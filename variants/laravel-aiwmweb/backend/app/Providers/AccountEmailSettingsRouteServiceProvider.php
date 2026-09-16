<?php

namespace App\Providers;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AccountEmailSettingsRouteServiceProvider extends ServiceProvider
{
    public const OPERATION_ID = 'AIMW-EMAI-B2CFCF818C';

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/account/email-settings', [CanonicalWorkspaceRouteController::class, 'show'])
            ->defaults('workspace_permissions', 'tenant.view')
            ->defaults('canonical_operation_id', self::OPERATION_ID)
            ->name('canonical.workspace.account-email-settings');
    }
}
