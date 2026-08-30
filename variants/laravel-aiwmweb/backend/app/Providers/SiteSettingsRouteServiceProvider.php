<?php

namespace App\Providers;

use App\Http\Controllers\SiteSettingsReadController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SiteSettingsRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/sites/{site}/settings', SiteSettingsReadController::class)
            ->whereNumber('site')
            ->defaults('workspace_permissions', 'tenant.view,sites.manage')
            ->defaults('canonical_operation_id', 'AIMW-SITE-9F9F2977B5')
            ->name('canonical.site.settings');
    }
}
