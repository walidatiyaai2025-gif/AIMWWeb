<?php

namespace App\Providers;

use App\Http\Controllers\AiCenterApprovalStatusController;
use App\Http\Controllers\AiCenterApprovalSubmissionController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AiCenterApprovalStatusRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/api/tenants/{tenant}/ai-center/approval-status', AiCenterApprovalStatusController::class)
            ->name('tenant.ai-center.approval-status');

        Route::middleware(['web', 'auth', 'tenant.context'])
            ->post('/api/tenants/{tenant}/ai-center/approvals', AiCenterApprovalSubmissionController::class)
            ->defaults('canonical_operation_id', AiCenterApprovalSubmissionController::OPERATION_ID)
            ->name('tenant.ai-center.approvals.submit');
    }
}
