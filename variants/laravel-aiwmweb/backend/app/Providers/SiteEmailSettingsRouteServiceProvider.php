<?php

namespace App\Providers;

use App\Email\Services\SiteEmailRecipientService;
use App\Http\Controllers\SiteEmailSettingsController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SiteEmailSettingsRouteServiceProvider extends ServiceProvider
{
    public const MODULE_ROUTE_OPERATION_ID = 'AIMW-EMAI-7F2D7C5921';

    public const SITE_ROUTE_OPERATION_ID = 'AIMW-EMAI-BFDC050625';

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/module/site-email-settings', [SiteEmailSettingsController::class, 'module'])
            ->defaults('canonical_operation_id', self::MODULE_ROUTE_OPERATION_ID)
            ->name('canonical.workspace.site-email-settings.module');

        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/sites/{site}/email-settings', [SiteEmailSettingsController::class, 'site'])
            ->defaults('canonical_operation_id', self::SITE_ROUTE_OPERATION_ID)
            ->whereNumber('site')
            ->name('canonical.workspace.site-email-settings.site');

        Route::middleware(['web', 'auth', 'tenant.context'])
            ->post('/tenants/{tenant}/sites/{site}/email-settings/recipients', [SiteEmailSettingsController::class, 'store'])
            ->defaults('canonical_operation_id', SiteEmailRecipientService::ADD_OPERATION_ID)
            ->whereNumber('site')
            ->name('canonical.workspace.site-email-settings.recipient-add');
    }
}
