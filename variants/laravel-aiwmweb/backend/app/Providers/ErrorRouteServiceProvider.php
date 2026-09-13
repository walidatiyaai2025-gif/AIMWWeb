<?php

namespace App\Providers;

use App\Http\Controllers\ErrorReadController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ErrorRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')
            ->get('/Error', ErrorReadController::class)
            ->name('canonical.error');
    }
}
