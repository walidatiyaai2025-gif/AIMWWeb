<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalWorkspaceRouteController;
use App\Http\Controllers\SiteManagementController;
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

class CurrentUserSiteDetailsVisibleTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SITE-D7DF8247B4';

    public function test_exact_canonical_operation_is_the_terminal_current_user_active_site_details_link(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('sites', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('component:CurrentUserChip', $operation['route_screen']);
        $this->assertSame('/sites/@_activeSite.Id -> /sites/@_activeSite.Id', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Layout/CurrentUserChip.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);

        $frontend = file_get_contents(resource_path('js/current-user-site-details-control.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('context.api[`sites.detail.${activeSite.id}`]', $frontend);
        $this->assertStringContainsString('tenantUrl(context.tenant.slug, `/sites/${activeSite.id}`)', $frontend);
    }

    public function test_active_site_context_and_real_site_details_route_are_authoritative_for_the_control(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Site');
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$site->id)
            ->assertOk();

        $context = $this->actingAs($user)
            ->getJson('/tenants/alpha/context')
            ->assertOk()
            ->assertJsonPath('active_site.id', $site->id)
            ->assertJsonPath('active_site.name', 'Alpha Site');
        $payload = $context->json();

        $this->assertSame(
            "/api/tenants/alpha/sites/{$site->id}",
            $payload['api']["sites.detail.{$site->id}"],
        );

        $workspace = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/'.$site->id, 'GET'));
        $this->assertSame('canonical.site.details', $workspace->getName());
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@showSite', ltrim($workspace->getActionName(), '\\'));
        $this->assertContains('auth', $workspace->gatherMiddleware());
        $this->assertContains('tenant.context', $workspace->gatherMiddleware());

        $api = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/'.$site->id, 'GET'));
        $this->assertSame(SiteManagementController::class.'@show', ltrim($api->getActionName(), '\\'));
        $this->actingAs($user)
            ->getJson('/api/tenants/alpha/sites/'.$site->id)
            ->assertOk()
            ->assertJsonPath('id', $site->id)
            ->assertJsonPath('name', 'Alpha Site');
    }

    public function test_control_contract_fails_closed_for_guest_missing_permission_and_foreign_site(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $beta = $this->membership($user, 'beta', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site');
        $betaSite = $this->site($beta, 'Beta Site');
        $this->withoutVite();

        $this->getJson('/tenants/alpha/context')->assertUnauthorized();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site');
        $this->actingAs($limited)->get('/tenants/limited/sites/'.$limitedSite->id)->assertForbidden();

        $this->actingAs($user)->get('/tenants/alpha/sites/'.$alphaSite->id)->assertOk();
        $this->actingAs($user)->get('/tenants/alpha/sites/'.$betaSite->id)->assertNotFound();
        $this->actingAs($user)->getJson('/api/tenants/alpha/sites/'.$betaSite->id)->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "current-user-site-details-{$slug}-{$user->id}"]);

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
            'url' => 'https://'.str($name)->slug().'.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }
}
