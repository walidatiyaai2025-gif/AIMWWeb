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

final class WordPressUsersBackToSitesControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-B1024628D9';

    public function test_exact_canonical_input_is_the_pending_wordpress_users_back_to_sites_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites/{SiteId:guid}/users', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/UsersManager.razor', $operation['current_source']);
        $this->assertSame('@L[ -> /sites', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_source_and_destination_use_explicit_tenant_guarded_routes(): void
    {
        $source = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/123/users', 'GET'));
        $destination = Route::getRoutes()->match(Request::create('/tenants/alpha/sites', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@redirectSite',
            ltrim($source->getActionName(), '\\'),
        );
        $this->assertSame('canonical.site.wordpress-users', $source->getName());
        $this->assertSame('tenant.view,sites.view', $source->defaults['workspace_permissions'] ?? null);
        $this->assertSame('/module/users', $source->defaults['workspace_target'] ?? null);
        $this->assertContains('auth', $source->gatherMiddleware());
        $this->assertContains('tenant.context', $source->gatherMiddleware());

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@show',
            ltrim($destination->getActionName(), '\\'),
        );
        $this->assertSame('tenant.view,sites.view', $destination->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $destination->gatherMiddleware());
        $this->assertContains('tenant.context', $destination->gatherMiddleware());
    }

    public function test_authorized_navigation_reaches_real_wordpress_users_and_sites_workspaces_without_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Site');
        $before = Site::withoutGlobalScopes()->findOrFail($site->id)->toArray();
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$site->id.'/users')
            ->assertRedirect('/tenants/alpha/module/users?site='.$site->id);

        $this->actingAs($user)
            ->get('/tenants/alpha/module/users?site='.$site->id)
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->get('/tenants/alpha/sites')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->getJson('/api/tenants/alpha/sites')
            ->assertOk()
            ->assertJsonFragment(['id' => $site->id, 'name' => 'Alpha Site']);

        $after = Site::withoutGlobalScopes()->findOrFail($site->id)->toArray();
        $this->assertSame($before, $after);
    }

    public function test_guest_missing_permission_and_foreign_tenant_or_site_access_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site');

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view']);
        $betaSite = $this->site($betaMembership, 'Beta Site');
        $this->withoutVite();

        $this->get('/tenants/alpha/sites/'.$alphaSite->id.'/users')->assertRedirect('/login');
        $this->get('/tenants/alpha/sites')->assertRedirect('/login');

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site');
        $this->actingAs($limited)
            ->get('/tenants/limited/sites/'.$limitedSite->id.'/users')
            ->assertForbidden();
        $this->actingAs($limited)
            ->get('/tenants/limited/sites')
            ->assertForbidden();

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/sites/'.$betaSite->id.'/users')
            ->assertNotFound();
        $this->actingAs($alphaUser)
            ->get('/tenants/beta/sites')
            ->assertNotFound();

        $this->assertDatabaseHas('sites', ['id' => $betaSite->id, 'tenant_id' => $betaMembership->tenant_id]);
    }

    public function test_production_wiring_contains_only_the_claimed_wordpress_users_control(): void
    {
        $component = (string) file_get_contents(resource_path('js/wordpress-users-back-to-sites-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $component);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/sites')", $component);
        $this->assertStringContainsString("route.key === 'wp-users'", $app);
        $this->assertStringContainsString('WordPressUsersBackToSitesControl', $app);
        $this->assertStringNotContainsString('fetch(', $component);
        $this->assertStringNotContainsString('apiRequest', $component);
        $this->assertStringNotContainsString('success', strtolower($component));
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "users-back-sites-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }
}
