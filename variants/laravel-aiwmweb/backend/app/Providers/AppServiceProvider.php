<?php

namespace App\Providers;

use App\Content\Remote\ContentRemoteDriver;
use App\Content\Remote\DualPathContentDriver;
use App\Models\TenantSecret;
use App\Policies\TenantSecretPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);
        $this->app->bind(ContentRemoteDriver::class, DualPathContentDriver::class);
    }

    public function boot(): void
    {
        Gate::policy(TenantSecret::class, TenantSecretPolicy::class);
    }
}
