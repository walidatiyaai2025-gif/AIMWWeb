<?php

namespace App\Providers;

use App\Http\Controllers\AiCenterApprovalStatusController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AiCenterApprovalStatusRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/api/tenants/{tenant}/ai-center/approval-status', AiCenterApprovalStatusController::class)
            ->name('tenant.ai-center.approval-status');
    }
}
