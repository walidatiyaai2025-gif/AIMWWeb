<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteOperationDetailsReadController;
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
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SiteOperationDetailsAliasRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-E5D089844A';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_operations_sites_alias_has_independent_canonical_provenance_and_guarded_read_contract(): void
    {
        $route = Route::getRoutes()->getByName('canonical.alias.operations-site-details');

        $this->assertNotNull($route);
        $this->assertSame('tenants/{tenant}/operations/sites/{operationId}', $route->uri());
        $this->assertSame(SiteOperationDetailsReadController::class, ltrim($route->getActionName(), '\\'));
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame('execution.view', $route->defaults['workspace_permissions']);
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id']);
    }

    public function test_authorized_tenant_member_reads_persisted_operation_through_alias_without_mutation_or_secret_leakage(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $user = User::factory()->create();
        $membership = $this->membership($user, $tenant, ['tenant.view', 'execution.view']);
        $correlationId = (string) Str::uuid();
        $operation = $this->recordOperation($membership, $correlationId, 'Alpha Site');

        $before = SiteOperationHistory::query()->withoutGlobalScopes()->count();

        $this->actingAs($user)
            ->get("/tenants/alpha/operations/sites/{$correlationId}")
            ->assertOk()
            ->assertSee('Site operation details')
            ->assertSee('Alpha Site')
            ->assertSee('content.sync')
            ->assertSee('succeeded')
            ->assertSee($correlationId)
            ->assertSee('Synced authoritative content')
            ->assertSee('[REDACTED]')
            ->assertDontSee('never-render-this-secret');

        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $resolved = app(SiteOperationHistoryService::class)->getByCorrelationId($correlationId);
        $context->forget();

        $this->assertSame($operation->id, $resolved?->id);
        $this->assertSame($before, SiteOperationHistory::query()->withoutGlobalScopes()->count());
    }

    public function test_alias_fails_closed_for_guest_missing_permission_foreign_tenant_cross_tenant_guid_and_malformed_guid(): void
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

        $this->get("/tenants/alpha/operations/sites/{$alphaCorrelationId}")->assertRedirect('/login');
        $this->actingAs($limited)->get("/tenants/alpha/operations/sites/{$alphaCorrelationId}")->assertForbidden();
        $this->actingAs($allowed)->get("/tenants/beta/operations/sites/{$betaCorrelationId}")->assertNotFound();
        $this->actingAs($allowed)->get("/tenants/alpha/operations/sites/{$betaCorrelationId}")->assertNotFound();
        $this->actingAs($allowed)->get('/tenants/alpha/operations/sites/not-a-guid')->assertNotFound();
        $this->actingAs($allowed)->get('/tenants/alpha/operations/sites/'.Str::uuid())->assertNotFound();
    }

    private function membership(User $user, Tenant $tenant, array $permissions): TenantMembership
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => 'site-operation-alias-'.$tenant->slug.'-'.$user->id]);
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
            ['trace_id' => 'trace-alias-42', 'api_token' => 'never-render-this-secret'],
            7,
            $correlationId,
            now()->subSeconds(2),
        );
        $context->forget();

        return $operation;
    }
}
