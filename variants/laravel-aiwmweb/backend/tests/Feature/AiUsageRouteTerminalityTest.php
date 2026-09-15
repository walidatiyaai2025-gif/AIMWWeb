<?php

namespace Tests\Feature;

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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiUsageRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-1E1BF9CEDC';

    public function test_exact_canonical_operation_is_the_pending_ai_usage_route(): void
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
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('/module/ai-usage', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AIUsage.razor', $operation['current_source']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_route_is_explicit_guarded_and_frontend_context_binds_the_real_api(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'ai.viewUsage']);
        $this->withoutVite();

        Artisan::call('route:list', ['--json' => true]);
        $routes = collect(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR));
        $route = $routes->firstWhere('name', 'canonical.workspace.ai-usage');
        $fallbackIndex = $routes->search(fn (array $candidate): bool => $candidate['uri'] === 'tenants/{tenant}/{path?}');
        $routeIndex = $routes->search(fn (array $candidate): bool => $candidate['name'] === 'canonical.workspace.ai-usage');

        $this->assertNotNull($route);
        $this->assertSame('tenants/{tenant}/module/ai-usage', $route['uri']);
        $this->assertIsInt($routeIndex);
        $this->assertIsInt($fallbackIndex);
        $this->assertLessThan($fallbackIndex, $routeIndex);

        $this->actingAs($user)
            ->get('/tenants/alpha/module/ai-usage')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->getJson('/tenants/alpha/context')
            ->assertOk()
            ->assertJsonPath('api.ai-usage', '/api/v1/tenants/alpha/ai/usage');

        $apiRoute = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/ai/usage', 'GET'));
        $this->assertSame(AiUsageReadController::class.'@index', $apiRoute->getActionName());
        $this->assertContains('tenant.context', $apiRoute->gatherMiddleware());
    }

    public function test_persisted_usage_is_current_user_and_tenant_scoped_with_authoritative_site_filtering(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $alphaMembership = $this->membership($user, 'alpha', ['tenant.view', 'ai.viewUsage']);
        $this->membershipOnTenant($otherUser, $alphaMembership->tenant, ['tenant.view', 'ai.viewUsage'], 'alpha-other');
        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view', 'ai.viewUsage']);

        $alphaSite = $this->site($alphaMembership->tenant, 'Alpha site');
        $alphaOtherSite = $this->site($alphaMembership->tenant, 'Alpha other');
        $betaSite = $this->site($betaMembership->tenant, 'Beta site');

        $mine = $this->usage($alphaMembership->tenant, $user, $alphaSite, 'openai', 'publish', 'succeeded', 10, 4, 0.0123);
        $mineOtherSite = $this->usage($alphaMembership->tenant, $user, $alphaOtherSite, 'gemini', 'audit', 'failed', 3, 2, 0.0045);
        $otherAccount = $this->usage($alphaMembership->tenant, $otherUser, $alphaSite, 'anthropic', 'draft', 'succeeded', 99, 88, 9.99);
        $foreignTenant = $this->usage($betaMembership->tenant, $betaUser, $betaSite, 'openai', 'foreign', 'succeeded', 77, 66, 7.77);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha/ai/usage?user_id='.$otherUser->id)
            ->assertOk()
            ->assertJsonPath('summary.total_calls', 2)
            ->assertJsonPath('summary.successful_calls', 1)
            ->assertJsonCount(2, 'data')
            ->assertJsonCount(2, 'recent')
            ->assertJsonCount(2, 'sites');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertContains($mineOtherSite->id, $ids);
        $this->assertNotContains($otherAccount->id, $ids);
        $this->assertNotContains($foreignTenant->id, $ids);

        $filtered = $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha/ai/usage?site='.$alphaSite->id)
            ->assertOk()
            ->assertJsonPath('summary.total_calls', 1)
            ->assertJsonCount(1, 'data');
        $this->assertSame($mine->id, $filtered->json('data.0.id'));

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha/ai/usage?site='.$betaSite->id)
            ->assertNotFound();
        $this->actingAs($user)
            ->get('/tenants/beta/module/ai-usage')
            ->assertNotFound();
    }

    public function test_authentication_permission_and_empty_state_contract_fail_closed_truthfully(): void
    {
        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->withoutVite();

        $this->get('/tenants/limited/module/ai-usage')->assertRedirect('/login');
        $this->getJson('/api/v1/tenants/limited/ai/usage')->assertUnauthorized();

        $this->actingAs($limited)->get('/tenants/limited/module/ai-usage')->assertForbidden();
        $this->actingAs($limited)->getJson('/api/v1/tenants/limited/ai/usage')->assertForbidden();

        $allowed = User::factory()->create();
        $this->membership($allowed, 'empty', ['tenant.view', 'ai.viewUsage']);
        $this->actingAs($allowed)
            ->getJson('/api/v1/tenants/empty/ai/usage')
            ->assertOk()
            ->assertJsonPath('summary.total_calls', 0)
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'data')
            ->assertJsonCount(0, 'recent');
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);

        return $this->membershipOnTenant($user, $tenant, $permissions, $slug);
    }

    private function membershipOnTenant(User $user, Tenant $tenant, array $permissions, string $roleSuffix): TenantMembership
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "ai-usage-{$roleSuffix}"]);
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

    private function usage(
        Tenant $tenant,
        User $user,
        Site $site,
        string $provider,
        string $workflow,
        string $status,
        int $input,
        int $output,
        float $cost,
    ): AiUsageRecord {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $record = AiUsageRecord::query()->create([
            'user_id' => $user->id,
            'provider_key' => $provider,
            'model_key' => 'test-model',
            'workflow' => $workflow,
            'input_units' => $input,
            'output_units' => $output,
            'estimated_cost' => $cost,
            'status' => $status,
            'latency_ms' => 25,
            'retry_count' => 0,
            'correlation_id' => (string) Str::uuid(),
            'metadata' => ['site_id' => $site->id],
            'created_at' => now(),
        ]);
        $context->forget();

        return $record;
    }
}
