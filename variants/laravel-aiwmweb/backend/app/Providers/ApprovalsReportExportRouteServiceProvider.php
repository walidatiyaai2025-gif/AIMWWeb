<?php

namespace App\Providers;

use App\Http\Controllers\ApprovalsReportExportController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ApprovalsReportExportRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'tenant.context'])
            ->prefix('tenants/{tenant}')
            ->group(function (): void {
                Route::get('/reports', [ApprovalsReportExportController::class, 'show'])
                    ->name('tenant.reports');
                Route::get('/module/reports', [ApprovalsReportExportController::class, 'show'])
                    ->name('tenant.module-reports');
                Route::get('/reports/approvals.csv', [ApprovalsReportExportController::class, 'download'])
                    ->name('tenant.reports.approvals-download');
            });
    }
}
