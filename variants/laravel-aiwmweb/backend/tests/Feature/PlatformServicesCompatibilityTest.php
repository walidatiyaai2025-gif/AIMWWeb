<?php

namespace Tests\Feature;

use App\AI\Platform\Services\AIProviderSettingsAdministrationService;
use App\AI\Platform\Services\AiUsageService;
use App\AI\Platform\Services\AIUsageWebService;
use App\Billing\AccountEntitlementEnforcementService;
use App\Billing\Enums\SubscriptionState;
use App\Billing\Exceptions\EntitlementDeniedException;
use App\Billing\Exceptions\QuotaExceededException;
use App\Email\Services\SiteMailProfileService;
use App\Models\BillingPlan;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\TenantUsageCounter;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformServicesCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_provider_administration_uses_tenant_permission_encrypted_secret_and_truthful_clear_state(): void
    {
        [$tenant, $membership] = $this->tenantMember('platform-ai', ['tenant.view', 'settings.manage']);
        $this->activate($tenant, $membership);

        $service = app(AIProviderSettingsAdministrationService::class);
        $saved = $service->saveAsync(['providers' => [[
            'provider_key' => 'openai',
            'adapter_key' => 'openai-compatible',
            'display_name' => 'OpenAI',
            'endpoint' => 'https://api.openai.com/v1',
            'default_model' => 'gpt-4o-mini',
            'enabled' => true,
            'api_key' => 'ai-secret-plain-text',
        ]]]);

        $this->assertTrue((bool) data_get($saved, 'providers.0.has_api_key'));
        $this->assertStringNotContainsString('ai-secret-plain-text', json_encode($saved, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(
            'ai-secret-plain-text',
            DB::table('tenant_secrets')->pluck('encrypted_value')->implode('|'),
        );

        $cleared = $service->clearApiKeyAsync('openai');
        $this->assertFalse($cleared['has_api_key']);
        $this->assertSame('NOT_CONFIGURED', $cleared['readiness']);

        [$deniedTenant, $deniedMembership] = $this->tenantMember('platform-ai-denied', ['tenant.view']);
        $this->activate($deniedTenant, $deniedMembership);
        $this->expectException(AuthorizationException::class);
        app(AIProviderSettingsAdministrationService::class)->getAsync();
    }

    public function test_ai_usage_web_service_validates_owned_site_and_never_crosses_tenant_scope(): void
    {
        [$tenantA, $memberA] = $this->tenantMember('platform-usage-a', ['tenant.view']);
        $this->activate($tenantA, $memberA);
        $siteA = Site::query()->create(['name' => 'Usage A', 'url' => 'https://usage-a.example.test']);
        app(AiUsageService::class)->record([
            'user_id' => $memberA->user_id,
            'provider_key' => 'openai',
            'model_key' => 'gpt-4o-mini',
            'workflow' => 'content.suggest',
            'input_units' => 10,
            'output_units' => 5,
            'estimated_cost' => 0.01,
            'actual_cost' => 0.01,
            'currency' => 'USD',
            'status' => 'succeeded',
            'retry_count' => 0,
            'correlation_id' => (string) Str::uuid(),
            'metadata' => ['site_id' => $siteA->id],
        ]);

        $report = app(AIUsageWebService::class)->getAsync($siteA->id, 100);
        $this->assertSame(1, $report['summary']['total_calls']);
        $this->assertCount(1, app(AIUsageWebService::class)->getRecentAsync(100, $siteA->id));

        [$tenantB, $memberB] = $this->tenantMember('platform-usage-b', ['tenant.view']);
        $this->activate($tenantB, $memberB);
        $siteB = Site::query()->create(['name' => 'Usage B', 'url' => 'https://usage-b.example.test']);

        $this->activate($tenantA, $memberA);
        try {
            app(AIUsageWebService::class)->getAsync($siteB->id, 100);
            $this->fail('Foreign-tenant site must not be accepted as an AI usage filter.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
        $this->assertSame(1, app(AIUsageWebService::class)->getAsync()['summary']['total_calls']);
    }

    public function test_account_entitlement_compatibility_checks_limits_without_consuming_usage(): void
    {
        [$tenant, $membership] = $this->tenantMember('platform-billing', ['tenant.view']);
        $this->activate($tenant, $membership);
        $plan = BillingPlan::query()->where('code', 'pro')->firstOrFail();
        TenantSubscription::query()->create([
            'billing_plan_id' => $plan->id,
            'state' => SubscriptionState::ACTIVE,
            'provider' => 'test',
            'started_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $service = app(AccountEntitlementEnforcementService::class);
        $service->requireBooleanCapabilityAsync('seo.audit.enabled');
        $service->requireAdditionalUsageAsync('ai.requests.month', 2999, 1);
        $this->assertSame(0, TenantUsageCounter::query()->count());

        try {
            $service->requireAdditionalUsageAsync('ai.requests.month', 3000, 1);
            $this->fail('Over-limit additional usage must be rejected.');
        } catch (QuotaExceededException) {
            $this->assertTrue(true);
        }

        $this->expectException(EntitlementDeniedException::class);
        $service->requireBooleanCapabilityAsync('capability.not.in.plan');
    }

    public function test_site_mail_profile_is_tenant_owned_secret_safe_and_rejects_foreign_site(): void
    {
        [$tenantA, $memberA] = $this->tenantMember('platform-mail-a', ['tenant.view', 'settings.manage']);
        $this->activate($tenantA, $memberA);
        $siteA = Site::query()->create(['name' => 'Mail A', 'url' => 'https://mail-a.example.test']);
        $service = app(SiteMailProfileService::class);

        $saved = $service->saveAsync($siteA->id, [
            'use_account_profile' => false,
            'host' => 'smtp.example.test',
            'port' => 587,
            'user_name' => 'mailer',
            'password' => 'smtp-secret-plain-text',
            'from_address' => 'owner@example.test',
            'from_name' => 'Owner',
            'reply_to_address' => 'reply@example.test',
            'enable_ssl' => true,
            'is_enabled' => true,
        ]);
        $this->assertTrue($saved['has_saved_password']);
        $this->assertStringNotContainsString('smtp-secret-plain-text', json_encode($saved, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(
            'smtp-secret-plain-text',
            DB::table('tenant_secrets')->pluck('encrypted_value')->implode('|'),
        );

        $delivery = $service->getDeliveryProfileAsync($siteA->id);
        $this->assertNotNull($delivery);
        $this->assertTrue($delivery['has_secret']);
        $this->assertArrayNotHasKey('secret', $delivery);
        $this->assertArrayNotHasKey('password', $delivery);

        $service->clearPasswordAsync($siteA->id);
        $this->assertFalse($service->getAsync($siteA->id)['has_saved_password']);

        [$tenantB, $memberB] = $this->tenantMember('platform-mail-b', ['tenant.view', 'settings.manage']);
        $this->activate($tenantB, $memberB);
        $siteB = Site::query()->create(['name' => 'Mail B', 'url' => 'https://mail-b.example.test']);

        $this->activate($tenantA, $memberA);
        $this->expectException(ModelNotFoundException::class);
        $service->getAsync($siteB->id);
    }

    private function tenantMember(string $slug, array $permissions): array
    {
        $context = app(TenantContext::class);
        $context->forget();
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create(['email' => $slug.'@example.test']);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-role']);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->setRelation('user', $user);
        $context->forget();

        return [$tenant, $membership];
    }

    private function activate(Tenant $tenant, TenantMembership $membership): void
    {
        $membership->setRelation('user', $membership->user()->withoutGlobalScopes()->firstOrFail());
        app(TenantContext::class)->activate($tenant, $membership);
        $this->actingAs($membership->user);
    }
}
