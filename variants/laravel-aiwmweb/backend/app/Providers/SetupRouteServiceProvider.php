<?php

namespace App\Providers;

use App\Http\Controllers\SetupReadController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SetupRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')
            ->get('/setup', SetupReadController::class)
            ->name('canonical.api.setup');
    }
}
