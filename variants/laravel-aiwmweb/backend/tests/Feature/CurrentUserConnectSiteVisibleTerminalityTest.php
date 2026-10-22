<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CurrentUserConnectSiteVisibleTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SITE-E3EA44AD3F';

    public function test_exact_canonical_operation_is_the_terminal_current_user_connect_site_control(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('sites', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('component:CurrentUserChip', $operation['route_screen']);
        $this->assertStringContainsString('/sites/connect', (string) $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Layout/CurrentUserChip.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_connect_destination_is_explicit_guarded_and_bound_to_the_canonical_operation(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'sites.manage']);
        $this->withoutVite();

        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/connect', 'GET'));
        $this->assertSame('canonical.site.connect', $route->getName());
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@show', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame('tenant.view,sites.manage', $route->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/connect')
            ->assertOk();
    }

    public function test_authoritative_sites_read_drives_empty_state_without_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.manage']);

        $this->assertSame(0, Site::query()->withoutGlobalScopes()->count());
        $this->actingAs($user)
            ->getJson('/api/tenants/alpha/sites')
            ->assertOk()
            ->assertExactJson([]);
        $this->assertSame(0, Site::query()->withoutGlobalScopes()->count());

        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        Site::query()->create(['name' => 'Existing Site', 'url' => 'https://existing.example', 'status' => 'active']);
        $context->forget();

        $this->actingAs($user)
            ->getJson('/api/tenants/alpha/sites')
            ->assertOk()
            ->assertJsonCount(1);
        $this->assertSame(1, Site::query()->withoutGlobalScopes()->count());
    }

    public function test_connect_destination_fails_closed_for_missing_permission_guest_and_foreign_tenant(): void
    {
        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->withoutVite();

        $this->actingAs($limited)
            ->get('/tenants/limited/sites/connect')
            ->assertForbidden();

        auth()->logout();
        $this->get('/tenants/limited/sites/connect')->assertRedirect();

        $authorized = User::factory()->create();
        $this->membership($authorized, 'alpha', ['tenant.view', 'sites.manage']);
        Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        $this->actingAs($authorized)
            ->get('/tenants/beta/sites/connect')
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "current-user-connect-site-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
