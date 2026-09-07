<?php

namespace App\Providers;

use App\Http\Controllers\PublicWelcomeReadController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class PublicWelcomeRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('web')
            ->get('/welcome', PublicWelcomeReadController::class)
            ->defaults('canonical_operation_id', 'AIMW-AI-4C07560F0B')
            ->name('public.welcome');
    }
}
