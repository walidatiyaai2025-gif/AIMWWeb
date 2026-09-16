<?php

namespace App\Providers;

use App\Http\Controllers\SiteCredentialController;
use App\Http\Controllers\SiteSettingsDeleteController;
use App\Http\Controllers\SiteSettingsReadController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SiteSettingsRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/sites/{site}/settings', SiteSettingsReadController::class)
            ->defaults('canonical_operation_id', 'AIMW-SITE-9F9F2977B5')
            ->whereNumber('site')
            ->name('canonical.site.settings');

        Route::middleware(['web', 'auth', 'tenant.context'])
            ->delete('/tenants/{tenant}/sites/{site}/settings', SiteSettingsDeleteController::class)
            ->defaults('canonical_operation_id', SiteSettingsDeleteController::CANONICAL_OPERATION_ID)
            ->whereNumber('site')
            ->name('canonical.site.settings.delete');

        Route::middleware(['web', 'auth', 'tenant.context'])
            ->delete('/tenants/{tenant}/sites/{site}/settings/credential', [SiteCredentialController::class, 'destroy'])
            ->defaults('canonical_operation_id', 'AIMW-BILL-E36C3E1427')
            ->whereNumber('site')
            ->name('canonical.site.settings.credential.destroy');
    }
}
