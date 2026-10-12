<?php

namespace App\Providers;

use App\Http\Controllers\ApplicationSessionsReadController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ApplicationSessionsRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/admin/sessions', ApplicationSessionsReadController::class)
            ->defaults('canonical_operation_id', ApplicationSessionsReadController::OPERATION_ID)
            ->name('canonical.application-sessions');
    }
}
