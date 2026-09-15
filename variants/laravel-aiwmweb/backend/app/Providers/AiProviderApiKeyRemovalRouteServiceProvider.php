<?php

namespace App\Providers;

use App\Http\Controllers\AiProviderApiKeyRemovalController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AiProviderApiKeyRemovalRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'tenant.context'])
            ->delete('/tenants/{tenant}/settings/ai-providers/{provider}/api-key', AiProviderApiKeyRemovalController::class)
            ->defaults('canonical_operation_id', AiProviderApiKeyRemovalController::OPERATION_ID)
            ->name('tenant.settings.ai-providers.api-key.destroy');
    }
}
