<?php

namespace Tests\Feature;

use App\Http\Controllers\SeoVisibleControlController;
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

final class SeoWorkspaceRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SEO-4CBBC7AAD9';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_workspace_route_is_explicit_and_bound_to_the_exact_canonical_operation(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/seo-workspace', 'GET'));

        $this->assertSame('canonical.workspace.seo-hub', $route->getName());
        $this->assertSame(SeoVisibleControlController::class.'@workspace', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_authorized_member_renders_the_real_tenant_qualified_seo_workspace(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'seo.view']);

        $this->actingAs($user)
            ->get('/tenants/alpha/seo-workspace')
            ->assertOk()
            ->assertViewIs('seo.workspace')
            ->assertViewHas('tenant', 'alpha')
            ->assertViewHas('links', static function (array $links): bool {
                return ($links['sites'] ?? null) === '/tenants/alpha/sites'
                    && ($links['audit'] ?? null) === '/tenants/alpha/module/seo-audit'
                    && ($links['suggestions'] ?? null) === '/tenants/alpha/module/seo-suggestions'
                    && ($links['approvals'] ?? null) === '/tenants/alpha/approvals';
            })
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('No sample findings or synthetic status are rendered here.');
    }

    public function test_guest_missing_permission_and_foreign_tenant_fail_closed(): void
    {
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $this->get('/tenants/'.$tenant->slug.'/seo-workspace')->assertRedirect();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)
            ->get('/tenants/limited/seo-workspace')
            ->assertForbidden();

        $authorized = User::factory()->create();
        $this->membership($authorized, 'owned', ['tenant.view', 'seo.view']);
        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);

        $this->actingAs($authorized)
            ->get('/tenants/foreign/seo-workspace')
            ->assertNotFound();
    }

    public function test_workspace_read_does_not_create_or_mutate_tenant_owned_records(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'seo.view']);
        $beforeMemberships = TenantMembership::withoutGlobalScopes()->count();
        $beforeTenants = Tenant::withoutGlobalScopes()->count();

        $this->actingAs($user)
            ->get('/tenants/alpha/seo-workspace')
            ->assertOk();

        $this->assertSame($beforeMemberships, TenantMembership::withoutGlobalScopes()->count());
        $this->assertSame($beforeTenants, Tenant::withoutGlobalScopes()->count());
        $this->assertSame($membership->tenant_id, Tenant::query()->where('slug', 'alpha')->value('id'));
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "seo-workspace-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
