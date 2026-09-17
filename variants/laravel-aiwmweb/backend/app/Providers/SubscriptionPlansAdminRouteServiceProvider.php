<?php

namespace App\Providers;

use App\Http\Controllers\SubscriptionPlanEnabledController;
use App\Http\Controllers\SubscriptionPlansAdminReadController;
use App\Http\Controllers\SubscriptionPlanSaveController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SubscriptionPlansAdminRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])->group(function (): void {
            Route::get('/tenants/{tenant}/admin/subscription-plans', SubscriptionPlansAdminReadController::class)
                ->name('tenant.admin.subscription-plans');
            Route::post('/tenants/{tenant}/admin/subscription-plans', [SubscriptionPlanSaveController::class, 'store'])
                ->name('tenant.admin.subscription-plans.save');
            Route::patch('/tenants/{tenant}/admin/subscription-plans/{plan}', [SubscriptionPlanSaveController::class, 'update'])
                ->whereNumber('plan')
                ->name('tenant.admin.subscription-plans.update');
            Route::patch('/tenants/{tenant}/admin/subscription-plans/{plan}/enabled', SubscriptionPlanEnabledController::class)
                ->whereNumber('plan')
                ->name('tenant.admin.subscription-plans.enabled');
        });
    }
}
