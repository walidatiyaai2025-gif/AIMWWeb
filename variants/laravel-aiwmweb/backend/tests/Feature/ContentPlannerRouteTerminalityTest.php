<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ContentPlannerRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-69825FEAD5';

    public function test_exact_canonical_operation_is_the_content_planner_route(): void
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
        $this->assertSame('route', $operation['kind']);
        $this->assertSame('/content-planner', $operation['route_screen']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_route_is_explicit_tenant_scoped_and_permission_guarded(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/content-planner', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@show',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('canonical.workspace.content-planner', $route->getName());
        $this->assertSame('tenant.view,content.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant'], $route->parameterNames());
    }

    public function test_authorized_user_renders_real_tenant_workspace(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'content.view'], 'Planner');
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/content-planner')
            ->assertOk()
            ->assertSee('id="app"', false);
    }

    public function test_guest_missing_permission_and_cross_tenant_access_fail_closed(): void
    {
        $this->withoutVite();
        $this->get('/tenants/alpha/content-planner')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view'], 'Limited');
        $this->actingAs($limited)->get('/tenants/limited/content-planner')->assertForbidden();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha', ['tenant.view', 'content.view'], 'Alpha');
        $beta = User::factory()->create();
        $this->membership($beta, 'beta', ['tenant.view', 'content.view'], 'Beta');

        $this->actingAs($alpha)->get('/tenants/beta/content-planner')->assertNotFound();
    }

    public function test_route_has_no_caller_supplied_resource_identifier_surface(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/content-planner', 'GET'));

        $this->assertSame(['tenant'], $route->parameterNames());
        $this->assertStringNotContainsString('{user}', $route->uri());
        $this->assertStringNotContainsString('{site}', $route->uri());
        $this->assertStringNotContainsString('{content}', $route->uri());
    }

    private function membership(User $user, string $slug, array $permissions, string $roleName): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => $roleName.'-'.$slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
