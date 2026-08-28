<?php

namespace App\Providers;

use App\Http\Controllers\ApprovalQueueReadController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ApprovalQueueRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/api/tenants/{tenant}/approvals', [ApprovalQueueReadController::class, 'index'])
            ->name('canonical.api.approvals.load');
    }
}
