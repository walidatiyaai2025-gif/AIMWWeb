<?php

namespace App\Providers;

use App\Http\Controllers\RegisterController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class RegisterRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')->group(function (): void {
            Route::get('/register', [RegisterController::class, 'show'])
                ->name('register');

            Route::post('/register', [RegisterController::class, 'store'])
                ->defaults('canonical_operation_id', RegisterController::OPERATION_ID)
                ->name('canonical.register.store');
        });
    }
}
