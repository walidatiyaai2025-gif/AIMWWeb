<?php

namespace App\Providers;

use App\Http\Controllers\AdminBillingSupportReadController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AdminBillingSupportRouteServiceProvider extends ServiceProvider
{
    public const OPERATION_ID = 'AIMW-BILL-5811B45F89';

    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'platform.admin'])
            ->get('/admin/billing-support', AdminBillingSupportReadController::class)
            ->defaults('canonical_operation_id', self::OPERATION_ID)
            ->name('canonical.admin.billing-support');
    }
}
