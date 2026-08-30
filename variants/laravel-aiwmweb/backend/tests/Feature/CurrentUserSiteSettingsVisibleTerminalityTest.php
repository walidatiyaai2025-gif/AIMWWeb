<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteSettingsReadController;
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

final class CurrentUserSiteSettingsVisibleTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SITE-9F9F2977B5';

    public function test_exact_canonical_operation_is_the_pending_current_user_site_settings_control(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('sites', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('component:CurrentUserChip', $operation['route_screen']);
        $this->assertStringContainsString('/settings', (string) $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Layout/CurrentUserChip.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_settings_destination_is_explicit_guarded_and_bound_to_the_canonical_operation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.manage']);
        $site = $this->site($membership, 'Alpha Site', 'https://alpha.example');

        $route = Route::getRoutes()->match(Request::create("/tenants/alpha/sites/{$site->id}/settings", 'GET'));
        $this->assertSame('canonical.site.settings', $route->getName());
        $this->assertSame(SiteSettingsReadController::class, ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame('tenant.view,sites.manage', $route->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_real_destination_renders_authoritative_site_snapshot_without_simulating_other_settings_mutations(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.manage']);
        $site = $this->site($membership, 'Alpha Site', 'https://alpha.example');

        $this->actingAs($user)
            ->get("/tenants/alpha/sites/{$site->id}/settings")
            ->assertOk()
            ->assertSee('Site Settings')
            ->assertSee('Alpha Site')
            ->assertSee('https://alpha.example')
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertDontSee('Save changes')
            ->assertDontSee('Save and test')
            ->assertDontSee('Remove credential')
            ->assertDontSee('Disable site')
            ->assertDontSee('Delete site');
    }

    public function test_settings_destination_fails_closed_for_missing_permission_guest_foreign_tenant_and_cross_tenant_site_id(): void
    {
        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site', 'https://limited.example');

        $this->actingAs($limited)
            ->get("/tenants/limited/sites/{$limitedSite->id}/settings")
            ->assertForbidden();

        auth()->logout();
        $this->get("/tenants/limited/sites/{$limitedSite->id}/settings")->assertRedirect();

        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'sites.manage']);
        $beta = $this->membership($user, 'beta', ['tenant.view', 'sites.manage']);
        $alphaSite = $this->site($alpha, 'Alpha Site', 'https://alpha.example');
        $betaSite = $this->site($beta, 'Beta Site', 'https://beta.example');

        $this->actingAs($user)
            ->get("/tenants/alpha/sites/{$betaSite->id}/settings")
            ->assertNotFound();

        $outsider = User::factory()->create();
        $this->membership($outsider, 'gamma', ['tenant.view', 'sites.manage']);
        $this->actingAs($outsider)
            ->get("/tenants/alpha/sites/{$alphaSite->id}/settings")
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "current-user-site-settings-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name, string $url): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);

        try {
            return Site::query()->create([
                'name' => $name,
                'url' => $url,
                'status' => 'active',
            ]);
        } finally {
            $context->forget();
        }
    }
}
