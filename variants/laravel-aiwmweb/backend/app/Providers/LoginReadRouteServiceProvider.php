<?php

namespace App\Providers;

use App\Http\Controllers\LoginReadController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class LoginReadRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')
            ->get('/login', LoginReadController::class)
            ->name('login');
    }
}
