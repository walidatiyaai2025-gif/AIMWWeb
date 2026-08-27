<?php

namespace App\Providers;

use App\Content\Remote\ContentRemoteDriver;
use App\Content\Remote\DualPathContentDriver;
use App\Models\TenantSecret;
use App\Policies\TenantSecretPolicy;
use App\Sync\CanonicalSyncSiteGuard;
use App\Sync\Contracts\SyncSiteGuard;
use App\Sync\Contracts\SyncWebhookVerifier;
use App\Sync\Webhooks\ConnectorSyncWebhookVerifier;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);
        $this->app->bind(ContentRemoteDriver::class, DualPathContentDriver::class);
        $this->app->bind(SyncSiteGuard::class, CanonicalSyncSiteGuard::class);
        $this->app->bind(SyncWebhookVerifier::class, ConnectorSyncWebhookVerifier::class);
    }

    public function boot(): void
    {
        Gate::policy(TenantSecret::class, TenantSecretPolicy::class);
    }
}
