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

class SiteDetailsBackControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-1C0C5D3B7B';

    public function test_exact_canonical_operation_is_the_pending_site_details_back_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites/{Id:guid}', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SiteDetails.razor', $operation['current_source']);
        $this->assertSame('/sites -> /sites', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_source_and_destination_are_real_explicit_routes_with_matching_permissions(): void
    {
        $source = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/1', 'GET'));
        $destination = Route::getRoutes()->match(Request::create('/tenants/alpha/sites', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@showSite',
            ltrim($source->getActionName(), '\\'),
        );
        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@show',
            ltrim($destination->getActionName(), '\\'),
        );
        $this->assertSame('tenant.view,sites.view', $source->defaults['workspace_permissions'] ?? null);
        $this->assertSame('tenant.view,sites.view', $destination->defaults['workspace_permissions'] ?? null);
        $this->assertContains('tenant.context', $source->gatherMiddleware());
        $this->assertContains('tenant.context', $destination->gatherMiddleware());
        $this->assertContains('auth', $source->gatherMiddleware());
        $this->assertContains('auth', $destination->gatherMiddleware());
    }

    public function test_authorized_user_can_open_real_site_details_and_real_sites_destination(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Site');
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$site->id)
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
    }

    public function test_guest_permission_and_cross_tenant_paths_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site');

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view']);
        $betaSite = $this->site($betaMembership, 'Beta Site');
        $this->withoutVite();

        $this->get('/tenants/alpha/sites/'.$alphaSite->id)->assertRedirect('/login');
        $this->get('/tenants/alpha/sites')->assertRedirect('/login');

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site');
        $this->actingAs($limited)->get('/tenants/limited/sites/'.$limitedSite->id)->assertForbidden();
        $this->actingAs($limited)->get('/tenants/limited/sites')->assertForbidden();

        $this->actingAs($alphaUser)->get('/tenants/alpha/sites/'.$betaSite->id)->assertNotFound();
        $this->actingAs($alphaUser)->get('/tenants/beta/sites')->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "site-back-{$slug}-{$user->id}"]);
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
