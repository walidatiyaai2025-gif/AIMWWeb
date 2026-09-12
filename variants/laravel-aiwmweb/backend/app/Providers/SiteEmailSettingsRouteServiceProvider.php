<?php

namespace App\Providers;

use App\Email\Services\SiteEmailRecipientService;
use App\Http\Controllers\SiteEmailSettingsController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SiteEmailSettingsRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/module/site-email-settings', [SiteEmailSettingsController::class, 'module'])
            ->name('canonical.workspace.site-email-settings.module');

        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/sites/{site}/email-settings', [SiteEmailSettingsController::class, 'site'])
            ->whereNumber('site')
            ->name('canonical.workspace.site-email-settings.site');

        Route::middleware(['web', 'auth', 'tenant.context'])
            ->post('/tenants/{tenant}/sites/{site}/email-settings/recipients', [SiteEmailSettingsController::class, 'store'])
            ->defaults('canonical_operation_id', SiteEmailRecipientService::ADD_OPERATION_ID)
            ->whereNumber('site')
            ->name('canonical.workspace.site-email-settings.recipient-add');
    }
}
