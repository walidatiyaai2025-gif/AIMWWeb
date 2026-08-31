<?php

namespace Tests\Feature;

use App\AI\Platform\Services\AiUsageService;
use App\Http\Controllers\AiUsageReadController;
use App\Models\AiUsageRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

final class AiUsageLoadTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-258E431558';

    public function test_exact_canonical_operation_is_the_adapted_ai_usage_load_async_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/module/ai-usage', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AIUsage.razor', $operation['current_source']);
        $this->assertStringContainsString('LoadAsync', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_operation_specific_frontend_loader_is_wired_only_on_ai_usage_and_reuses_existing_route_contract(): void
    {
        $control = (string) file_get_contents(resource_path('js/ai-usage-load-workspace.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("url.searchParams.set('site', siteId)", $control);
        $this->assertStringContainsString("context.permissions.includes('tenant.view')", $control);
        $this->assertStringContainsString("context.permissions.includes('ai.viewUsage')", $control);
        $this->assertStringContainsString('setSnapshot(payload)', $control);
        $this->assertStringContainsString('setError(errorMessage(reason, locale))', $control);
        $this->assertStringContainsString('AiUsageLoadWorkspace', $app);
        $this->assertStringContainsString("route.key === 'ai-usage'", $app);
        $this->assertStringContainsString('<AiUsageAiCenterLinkControl context={context} />', $app);
        $this->assertStringNotContainsString('AIMW-AI-411CFF23F3', $control);
        $this->assertStringNotContainsString('AIMW-AI-1E1BF9CEDC', $control);
    }

    public function test_load_async_uses_the_source_retention_ceiling_and_authoritative_read_is_non_mutating(): void
    {
        $this->assertSame(10_000, (new ReflectionClass(AiUsageService::class))->getConstant('MAX_REPORT_RECORDS'));
        $this->assertSame(10_000, (new ReflectionClass(AiUsageReadController::class))->getConstant('SOURCE_LOAD_TAKE'));

        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'ai.viewUsage']);
        $this->membershipOnTenant($otherUser, $membership->tenant, ['tenant.view', 'ai.viewUsage'], 'other');
        $site = $this->site($membership->tenant, 'Alpha site');
        $otherSite = $this->site($membership->tenant, 'Other site');

        $mine = $this->usage($membership->tenant, $user, $site, 'mine');
        $mineOtherSite = $this->usage($membership->tenant, $user, $otherSite, 'mine-other');
        $notMine = $this->usage($membership->tenant, $otherUser, $site, 'other-user');
        $before = AiUsageRecord::query()->withoutGlobalScopes()->count();

        $response = $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha/ai/usage')
            ->assertOk()
            ->assertJsonPath('summary.total_calls', 2)
            ->assertJsonCount(2, 'recent');

        $ids = collect($response->json('recent'))->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertContains($mineOtherSite->id, $ids);
        $this->assertNotContains($notMine->id, $ids);

        $filtered = $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha/ai/usage?site='.$site->id)
            ->assertOk()
            ->assertJsonPath('summary.total_calls', 1)
            ->assertJsonCount(1, 'recent');
        $this->assertSame($mine->id, $filtered->json('recent.0.id'));

        $this->assertSame($before, AiUsageRecord::query()->withoutGlobalScopes()->count());
    }

    public function test_guest_permission_and_foreign_site_boundaries_fail_closed(): void
    {
        $this->getJson('/api/v1/tenants/alpha/ai/usage')->assertUnauthorized();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)->getJson('/api/v1/tenants/limited/ai/usage')->assertForbidden();

        $allowed = User::factory()->create();
        $alpha = $this->membership($allowed, 'alpha', ['tenant.view', 'ai.viewUsage']);
        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['tenant.view', 'ai.viewUsage']);
        $foreignSite = $this->site($beta->tenant, 'Foreign site');

        $this->actingAs($allowed)
            ->getJson('/api/v1/tenants/alpha/ai/usage?site='.$foreignSite->id)
            ->assertNotFound();

        $this->assertNotSame($limitedMembership->tenant_id, $alpha->tenant_id);
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);

        return $this->membershipOnTenant($user, $tenant, $permissions, $slug);
    }

    /** @param list<string> $permissions */
    private function membershipOnTenant(User $user, Tenant $tenant, array $permissions, string $suffix): TenantMembership
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "ai-usage-load-{$suffix}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(Tenant $tenant, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.Str::slug($name).'.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }

    private function usage(Tenant $tenant, User $user, Site $site, string $workflow): AiUsageRecord
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $record = AiUsageRecord::query()->create([
            'user_id' => $user->id,
            'provider_key' => 'openai',
            'model_key' => 'test-model',
            'workflow' => $workflow,
            'input_units' => 10,
            'output_units' => 5,
            'estimated_cost' => 0.001,
            'status' => 'succeeded',
            'latency_ms' => 20,
            'retry_count' => 0,
            'correlation_id' => (string) Str::uuid(),
            'metadata' => ['site_id' => $site->id],
            'created_at' => now(),
        ]);
        $context->forget();

        return $record;
    }
}
