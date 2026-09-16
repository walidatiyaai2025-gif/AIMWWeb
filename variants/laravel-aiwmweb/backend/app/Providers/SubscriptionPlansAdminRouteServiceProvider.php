<?php

namespace App\Providers;

use App\Http\Controllers\SubscriptionPlansAdminReadController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SubscriptionPlansAdminRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/admin/subscription-plans', SubscriptionPlansAdminReadController::class)
            ->name('tenant.admin.subscription-plans');
    }
}
