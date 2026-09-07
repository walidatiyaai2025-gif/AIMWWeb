<?php

namespace App\Providers;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AiWorkspaceRouteServiceProvider extends ServiceProvider
{
    public const OPERATION_ID = 'AIMW-AI-8EE4F9F6FC';

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/ai-workspace', [CanonicalWorkspaceRouteController::class, 'show'])
            ->defaults('workspace_permissions', 'tenant.view')
            ->defaults('canonical_operation_id', self::OPERATION_ID)
            ->name('canonical.workspace.ai-workspace');
    }
}
