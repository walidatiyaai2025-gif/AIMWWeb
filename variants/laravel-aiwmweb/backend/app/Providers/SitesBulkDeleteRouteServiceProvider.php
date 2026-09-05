<?php

namespace App\Providers;

use App\Http\Controllers\SiteManagementController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SitesBulkDeleteRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->delete('/api/tenants/{tenant}/sites', [SiteManagementController::class, 'bulkDestroy'])
            ->defaults('canonical_operation', 'AIMW-BILL-337E4FF969')
            ->name('canonical.api.sites.bulk-delete');
    }
}
