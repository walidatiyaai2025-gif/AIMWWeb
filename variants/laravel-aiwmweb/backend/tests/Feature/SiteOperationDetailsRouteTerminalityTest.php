<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteOperationDetailsReadController;
use App\Http\Controllers\SiteOperationsMaintenanceReadController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteOperationHistory;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Sites\SiteOperationHistoryService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class SiteOperationDetailsRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-3CDB30A4C2';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_is_the_adapted_guid_site_operation_details_route(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('/site-operations/{OperationId:guid}', $operation['route_screen']);
        $this->assertSame('Open/render route', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SiteOperationDetails.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_canonical_and_source_alias_routes_are_explicit_uuid_guarded_reads(): void
    {
        $canonical = Route::getRoutes()->getByName('canonical.workspace.site-operation-details');
        $alias = Route::getRoutes()->getByName('canonical.alias.operations-site-details');

        $this->assertNotNull($canonical);
        $this->assertNotNull($alias);
        $this->assertSame('tenants/{tenant}/site-operations/{operationId}', $canonical->uri());
        $this->assertSame('tenants/{tenant}/operations/sites/{operationId}', $alias->uri());

        foreach ([$canonical, $alias] as $route) {
            $this->assertSame(SiteOperationDetailsReadController::class, ltrim($route->getActionName(), '\\'));
            $this->assertSame(['GET', 'HEAD'], $route->methods());
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('tenant.context', $route->gatherMiddleware());
            $this->assertSame('execution.view', $route->defaults['workspace_permissions']);
            $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id']);
        }

        $maintenance = Route::getRoutes()->match(Request::create('/tenants/alpha/site-operations/maintenance', 'GET'));
        $this->assertSame(SiteOperationsMaintenanceReadController::class, ltrim($maintenance->getActionName(), '\\'));
        $this->assertSame('canonical.workspace.site-operations-maintenance', $maintenance->getName());
    }

    public function test_authorized_tenant_member_reads_real_operation_by_correlation_guid_without_mutation(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $user = User::factory()->create();
        $membership = $this->membership($user, $tenant, ['tenant.view', 'execution.view']);
        $correlationId = (string) Str::uuid();
        $operation = $this->recordOperation($membership, $correlationId, 'Alpha Site');

        $before = SiteOperationHistory::query()->withoutGlobalScopes()->count();

        $response = $this->actingAs($user)->get("/tenants/alpha/site-operations/{$correlationId}");
        $response->assertOk()
            ->assertSee('Site operation details')
            ->assertSee('Alpha Site')
            ->assertSee('content.sync')
            ->assertSee('succeeded')
            ->assertSee($correlationId)
            ->assertSee('Synced authoritative content')
            ->assertSee('[REDACTED]')
            ->assertDontSee('never-render-this-secret');

        $this->actingAs($user)
            ->get("/tenants/alpha/operations/sites/{$correlationId}")
            ->assertOk()
            ->assertSee($correlationId);

        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $resolved = app(SiteOperationHistoryService::class)->getByCorrelationId($correlationId);
        $context->forget();

        $this->assertSame($operation->id, $resolved?->id);
        $this->assertSame($before, SiteOperationHistory::query()->withoutGlobalScopes()->count());
    }

    public function test_route_fails_closed_for_guest_missing_permission_foreign_tenant_and_cross_tenant_guid(): void
    {
        $alpha = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $allowed = User::factory()->create();
        $limited = User::factory()->create();
        $betaUser = User::factory()->create();
        $alphaMembership = $this->membership($allowed, $alpha, ['tenant.view', 'execution.view']);
        $this->membership($limited, $alpha, ['tenant.view']);
        $betaMembership = $this->membership($betaUser, $beta, ['tenant.view', 'execution.view']);
        $alphaCorrelationId = (string) Str::uuid();
        $betaCorrelationId = (string) Str::uuid();
        $this->recordOperation($alphaMembership, $alphaCorrelationId, 'Alpha Site');
        $this->recordOperation($betaMembership, $betaCorrelationId, 'Beta Secret Site');

        $this->get("/tenants/alpha/site-operations/{$alphaCorrelationId}")->assertRedirect('/login');
        $this->actingAs($limited)->get("/tenants/alpha/site-operations/{$alphaCorrelationId}")->assertForbidden();
        $this->actingAs($allowed)->get("/tenants/beta/site-operations/{$betaCorrelationId}")->assertNotFound();
        $this->actingAs($allowed)->get("/tenants/alpha/site-operations/{$betaCorrelationId}")->assertNotFound();
        $this->actingAs($allowed)->get('/tenants/alpha/site-operations/not-a-guid')->assertNotFound();
        $this->actingAs($allowed)->get('/tenants/alpha/operations/sites/not-a-guid')->assertNotFound();
    }

    private function membership(User $user, Tenant $tenant, array $permissions): TenantMembership
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => 'site-operation-details-'.$tenant->slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->load('tenant');
        $context->forget();

        return $membership;
    }

    private function recordOperation(TenantMembership $membership, string $correlationId, string $siteName): SiteOperationHistory
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => $siteName,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $siteName)).'.example.test',
        ]);
        $operation = app(SiteOperationHistoryService::class)->record(
            $site->id,
            'content.sync',
            true,
            'Synced authoritative content',
            ['trace_id' => 'trace-42', 'api_token' => 'never-render-this-secret'],
            7,
            $correlationId,
            now()->subSeconds(2),
        );
        $context->forget();

        return $operation;
    }
}
