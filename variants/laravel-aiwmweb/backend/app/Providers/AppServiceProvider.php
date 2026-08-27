<?php

namespace App\Providers;

use App\AI\AiProvider;
use App\AI\HttpAiProvider;
use App\Connector\HttpWordPressGateway;
use App\Connector\WordPressGateway;
use App\Models\TenantSecret;
use App\Policies\TenantSecretPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);
        $this->app->bind(WordPressGateway::class, HttpWordPressGateway::class);
        $this->app->bind(AiProvider::class, HttpAiProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(TenantSecret::class, TenantSecretPolicy::class);
    }
}
