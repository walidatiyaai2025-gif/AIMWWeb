<?php

namespace App\Providers;

use App\Http\Controllers\ApplicationUserPasswordResetController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ApplicationUserPasswordResetRouteServiceProvider extends ServiceProvider
{
    public const OPERATION_ID = 'AIMW-BILL-3BE40F2E00';

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->post('/tenants/{tenant}/admin/members/{membership}/reset-password', ApplicationUserPasswordResetController::class)
            ->defaults('canonical_operation_id', self::OPERATION_ID)
            ->name('canonical.application-users.reset-password');
    }
}
