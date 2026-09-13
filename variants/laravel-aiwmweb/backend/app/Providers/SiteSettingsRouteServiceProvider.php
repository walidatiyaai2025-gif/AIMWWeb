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
            ->defaults('canonical_operation_id', 'AIMW-SITE-9F9F2977B5')
            ->whereNumber('site')
            ->name('canonical.site.settings');
    }
}
