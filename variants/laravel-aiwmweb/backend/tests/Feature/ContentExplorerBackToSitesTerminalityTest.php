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

final class ContentExplorerBackToSitesTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-966F49CDAE';

    public function test_exact_canonical_input_is_the_pending_content_explorer_back_to_sites_control(): void
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
        $this->assertSame('/sites/{Id:guid}/explorer', $operation['route_screen']);
        $this->assertSame('/sites -> /sites', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_source_and_destination_use_real_tenant_guarded_routes(): void
    {
        $source = Route::getRoutes()->match(Request::create('/tenants/alpha/explorer?site=1', 'GET'));
        $destination = Route::getRoutes()->match(Request::create('/tenants/alpha/sites', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@showSiteBound',
            ltrim($source->getActionName(), '\\'),
        );
        $this->assertSame('sites.view', $source->defaults['workspace_permissions'] ?? null);
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

    public function test_authorized_user_reaches_real_explorer_and_sites_workspaces_without_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Explorer Site');
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/explorer?site='.$site->id)
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->get('/tenants/alpha/sites')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->getJson('/api/tenants/alpha/sites')
            ->assertOk()
            ->assertJsonFragment(['id' => $site->id, 'name' => 'Alpha Explorer Site']);

        $this->assertDatabaseHas('sites', [
            'id' => $site->id,
            'tenant_id' => $membership->tenant_id,
            'name' => 'Alpha Explorer Site',
        ]);
    }

    public function test_guest_permission_and_foreign_tenant_access_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site');

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view']);
        $betaSite = $this->site($betaMembership, 'Beta Site');
        $this->withoutVite();

        $this->get('/tenants/alpha/explorer?site='.$alphaSite->id)->assertRedirect('/login');
        $this->get('/tenants/alpha/sites')->assertRedirect('/login');

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site');
        $this->actingAs($limited)->get('/tenants/limited/explorer?site='.$limitedSite->id)->assertForbidden();
        $this->actingAs($limited)->get('/tenants/limited/sites')->assertForbidden();

        $this->actingAs($alphaUser)->get('/tenants/alpha/explorer?site='.$betaSite->id)->assertNotFound();
        $this->actingAs($alphaUser)->get('/tenants/beta/sites')->assertNotFound();
    }

    public function test_production_wiring_is_read_only_and_tenant_derived(): void
    {
        $component = (string) file_get_contents(resource_path('js/content-explorer-back-to-sites-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor'),
        );

        $this->assertStringContainsString('<a class="btn" href="/sites">', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $component);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/sites')", $component);
        $this->assertStringContainsString("route.key === 'explorer'", $pages);
        $this->assertStringContainsString('ContentExplorerBackToSitesControl', $pages);
        $this->assertStringNotContainsString('fetch(', $component);
        $this->assertStringNotContainsString('apiRequest', $component);
        $this->assertStringNotContainsString('window.location', $component);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "explorer-back-{$slug}-{$user->id}"]);
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
