<?php

namespace App\Providers;

use App\Email\Services\SiteEmailRecipientService;
use App\Email\Services\SiteMailProfileService;
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
            ->get('/tenants/{tenant}/sites/{site}/email-diagnostics', [SiteEmailSettingsController::class, 'diagnostics'])
            ->whereNumber('site')
            ->name('canonical.workspace.site-email-diagnostics');

        Route::middleware(['web', 'auth', 'tenant.context'])
            ->post('/tenants/{tenant}/sites/{site}/email-diagnostics/send-test', [SiteEmailSettingsController::class, 'sendTest'])
            ->defaults('canonical_operation_id', SiteEmailSettingsController::SEND_TEST_OPERATION_ID)
            ->whereNumber('site')
            ->name('canonical.workspace.site-email-diagnostics.send-test');

        Route::middleware(['web', 'auth', 'tenant.context'])
            ->post('/tenants/{tenant}/sites/{site}/email-settings/mail-profile', [SiteEmailSettingsController::class, 'saveMailProfile'])
            ->defaults('canonical_operation_id', SiteEmailSettingsController::SAVE_MAIL_PROFILE_OPERATION_ID)
            ->whereNumber('site')
            ->name('canonical.workspace.site-email-settings.mail-profile-save');

        Route::middleware(['web', 'auth', 'tenant.context'])
            ->delete('/tenants/{tenant}/sites/{site}/email-settings/mail-profile/password', [SiteEmailSettingsController::class, 'clearMailPassword'])
            ->defaults('canonical_operation_id', SiteMailProfileService::CLEAR_PASSWORD_OPERATION_ID)
            ->whereNumber('site')
            ->name('canonical.workspace.site-email-settings.mail-profile-password-clear');

        Route::middleware(['web', 'auth', 'tenant.context'])
            ->patch('/tenants/{tenant}/sites/{site}/email-settings/recipients/{recipient}', [SiteEmailSettingsController::class, 'saveEdit'])
            ->defaults('canonical_operation_id', SiteEmailRecipientService::UPDATE_OPERATION_ID)
            ->whereNumber('site')
            ->whereNumber('recipient')
            ->name('canonical.workspace.site-email-settings.recipient-update');

        Route::middleware(['web', 'auth', 'tenant.context'])
            ->post('/tenants/{tenant}/sites/{site}/email-settings/recipients', [SiteEmailSettingsController::class, 'store'])
            ->defaults('canonical_operation_id', SiteEmailRecipientService::ADD_OPERATION_ID)
            ->whereNumber('site')
            ->name('canonical.workspace.site-email-settings.recipient-add');
    }
}
