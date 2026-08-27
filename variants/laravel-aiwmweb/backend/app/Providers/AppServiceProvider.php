<?php

namespace App\Providers;

use App\AI\Platform\Approval\UnconfiguredPlannerApprovalGateway;
use App\AI\Platform\Approval\UnconfiguredPlannerSiteGateway;
use App\AI\Platform\Contracts\AiGenerator;
use App\AI\Platform\Contracts\AiQuotaGateway;
use App\AI\Platform\Contracts\PlannerApprovalGateway;
use App\AI\Platform\Contracts\PlannerSiteGateway;
use App\AI\Platform\Quota\UnconfiguredAiQuotaGateway;
use App\AI\Platform\Services\AiGenerationService;
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
        $this->app->bind(AiQuotaGateway::class, UnconfiguredAiQuotaGateway::class);
        $this->app->bind(AiGenerator::class, AiGenerationService::class);
        $this->app->bind(PlannerApprovalGateway::class, UnconfiguredPlannerApprovalGateway::class);
        $this->app->bind(PlannerSiteGateway::class, UnconfiguredPlannerSiteGateway::class);
    }

    public function boot(): void
    {
        Gate::policy(TenantSecret::class, TenantSecretPolicy::class);
    }
}
