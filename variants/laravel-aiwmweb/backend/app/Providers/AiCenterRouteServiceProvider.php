<?php

namespace App\Providers;

use App\Http\Controllers\AiCenterReadController;
use App\Http\Controllers\CanonicalWorkspaceRouteController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AiCenterRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/ai-center', [CanonicalWorkspaceRouteController::class, 'show'])
            ->defaults('workspace_permissions', 'tenant.view,ai.use')
            ->defaults('canonical_operation_id', 'AIMW-AI-82F795EE67')
            ->name('canonical.workspace.ai-center');

        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/api/tenants/{tenant}/ai-center', AiCenterReadController::class)
            ->name('tenant.ai-center.read');
    }
}
