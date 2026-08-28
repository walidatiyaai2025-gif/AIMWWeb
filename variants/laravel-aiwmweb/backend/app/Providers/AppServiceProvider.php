<?php

namespace App\Providers;

use App\AI\AiProvider;
use App\AI\HttpAiProvider;
use App\AI\Platform\Approval\UnconfiguredPlannerApprovalGateway;
use App\AI\Platform\Approval\UnconfiguredPlannerSiteGateway;
use App\AI\Platform\Contracts\AiGenerator;
use App\AI\Platform\Contracts\AiQuotaGateway;
use App\AI\Platform\Contracts\PlannerApprovalGateway;
use App\AI\Platform\Contracts\PlannerSiteGateway;
use App\AI\Platform\Quota\UnconfiguredAiQuotaGateway;
use App\AI\Platform\Services\AiGenerationService;
use App\Billing\Providers\BillingProvider;
use App\Billing\Providers\PayPalProvider;
use App\Connector\AdvancedWordPressGateway;
use App\Connector\HttpWordPressGateway;
use App\Connector\WordPressGateway;
use App\Content\Remote\ContentRemoteDriver;
use App\Content\Remote\DualPathContentDriver;
use App\Email\Contracts\EmailTransport;
use App\Email\Contracts\NotificationEventSink;
use App\Email\Services\NotificationPlatformService;
use App\Email\Services\SymfonyEmailTransport;
use App\Email\Services\SyncNotificationSubscriber;
use App\Models\TenantSecret;
use App\Policies\TenantSecretPolicy;
use App\Sync\CanonicalSyncSiteGuard;
use App\Sync\Contracts\SyncSiteGuard;
use App\Sync\Contracts\SyncWebhookVerifier;
use App\Sync\Webhooks\ConnectorSyncWebhookVerifier;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);
        $this->app->bind(WordPressGateway::class, HttpWordPressGateway::class);
        $this->app->bind(AdvancedWordPressGateway::class, HttpWordPressGateway::class);
        $this->app->bind(AiProvider::class, HttpAiProvider::class);
        $this->app->bind(ContentRemoteDriver::class, DualPathContentDriver::class);
        $this->app->bind(SyncSiteGuard::class, CanonicalSyncSiteGuard::class);
        $this->app->bind(SyncWebhookVerifier::class, ConnectorSyncWebhookVerifier::class);
        $this->app->bind(BillingProvider::class, PayPalProvider::class);
        $this->app->bind(AiQuotaGateway::class, UnconfiguredAiQuotaGateway::class);
        $this->app->bind(AiGenerator::class, AiGenerationService::class);
        $this->app->bind(PlannerApprovalGateway::class, UnconfiguredPlannerApprovalGateway::class);
        $this->app->bind(PlannerSiteGateway::class, UnconfiguredPlannerSiteGateway::class);
        $this->app->bind(EmailTransport::class, SymfonyEmailTransport::class);
        $this->app->bind(NotificationEventSink::class, NotificationPlatformService::class);
    }

    public function boot(): void
    {
        Gate::policy(TenantSecret::class, TenantSecretPolicy::class);
        Event::subscribe(SyncNotificationSubscriber::class);
    }
}
