<?php

namespace App\Providers;

use App\Http\Controllers\SecurityAuditReadController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SecurityAuditRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'platform.admin'])
            ->get('/admin/security-audit', SecurityAuditReadController::class)
            ->defaults('canonical_operation_id', SecurityAuditReadController::OPERATION_ID)
            ->name('canonical.admin.security-audit');
    }
}
