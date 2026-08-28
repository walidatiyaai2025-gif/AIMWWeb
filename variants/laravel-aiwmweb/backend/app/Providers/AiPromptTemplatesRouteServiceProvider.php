<?php

namespace App\Providers;

use App\Http\Controllers\AiPromptTemplatesReadController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AiPromptTemplatesRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->get('/tenants/{tenant}/settings/ai-prompts', AiPromptTemplatesReadController::class)
            ->name('tenant.settings.ai-prompts');
    }
}
