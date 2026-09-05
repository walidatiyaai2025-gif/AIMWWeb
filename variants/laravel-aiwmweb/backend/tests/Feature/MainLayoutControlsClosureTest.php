<?php

namespace Tests\Feature;

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

class MainLayoutControlsClosureTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATIONS = [
        'AIMW-AI-672DA063EF',
        'AIMW-AI-AE553AB4D0',
        'AIMW-AI-4A3B180ACC',
        'AIMW-AI-3399ECA4F2',
        'AIMW-AI-2C653A870A',
        'AIMW-AI-E3FD23F827',
        'AIMW-AI-2E423C956E',
        'AIMW-AI-EEDA94D1D2',
        'AIMW-AI-91156B1C8B',
        'AIMW-AI-F08307E7FD',
        'AIMW-BILL-C12CEEC7C6',
    ];

    public function test_main_layout_controls_are_bound_to_exact_canonical_rows_and_production_markers(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $rows = collect($document['operations'])->keyBy('operation_id');
        $production = (string) file_get_contents(resource_path('js/main-layout-parity-controls.tsx'));

        foreach (self::OPERATIONS as $operationId) {
            $row = $rows->get($operationId);
            $this->assertNotNull($row, "Missing canonical row {$operationId}");
            $this->assertSame('visible_control', $row['kind']);
            $this->assertSame('component:MainLayout', $row['route_screen']);
            $this->assertSame('src/AIWordPressManager.Web/Components/Layout/MainLayout.razor', $row['current_source']);
            $this->assertFalse((bool) $row['mutation']);
            $this->assertTrue((bool) $row['tenant_owned']);
            $this->assertStringContainsString($operationId, $production);
        }
    }

    public function test_main_layout_uses_real_authenticated_tenant_context_and_fails_closed_for_foreign_tenant(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/context', 'GET'));
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $alphaUser = User::factory()->create();
        $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view']);

        $betaUser = User::factory()->create();
        $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view']);

        $limitedUser = User::factory()->create();
        $this->membership($limitedUser, 'limited', ['sites.view']);

        $this->getJson('/tenants/alpha/context')->assertUnauthorized();
        $this->actingAs($limitedUser)->getJson('/tenants/limited/context')->assertForbidden();
        $this->actingAs($alphaUser)->getJson('/tenants/beta/context')->assertNotFound();

        $response = $this->actingAs($alphaUser)->getJson('/tenants/alpha/context')->assertOk();
        $response->assertJsonPath('tenant.slug', 'alpha');
        $this->assertContains('tenant.view', $response->json('permissions'));
        $this->assertContains('sites.view', $response->json('permissions'));
    }

    public function test_about_build_destination_is_explicitly_tenant_guarded(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/about-build', 'GET'));
        $middleware = $route->gatherMiddleware();

        $this->assertContains('auth', $middleware);
        $this->assertContains('tenant.context', $middleware);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "main-layout-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
