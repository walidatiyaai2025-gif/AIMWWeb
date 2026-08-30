<?php

namespace App\Providers;

use App\Http\Controllers\ApprovalsReportExportController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ApprovalsReportExportRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->prefix('tenants/{tenant}')
            ->group(function (): void {
                Route::get('/reports/approvals.csv', [ApprovalsReportExportController::class, 'download'])
                    ->name('tenant.reports.approvals-download');
                Route::get('/reports/sites.csv', [ApprovalsReportExportController::class, 'downloadSites'])
                    ->name('tenant.reports.sites-download');
            });

        $this->app->booted(function (): void {
            Route::middleware(['web', 'auth', 'tenant.context'])
                ->prefix('tenants/{tenant}')
                ->group(function (): void {
                    Route::get('/reports', [ApprovalsReportExportController::class, 'show'])
                        ->name('canonical.alias.reports');
                    Route::get('/module/reports', [ApprovalsReportExportController::class, 'show'])
                        ->name('canonical.workspace.reports');
                });
        });
    }
}
