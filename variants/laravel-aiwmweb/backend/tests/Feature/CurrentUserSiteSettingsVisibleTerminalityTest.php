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

class CurrentUserSiteSettingsVisibleTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SITE-9F9F2977B5';

    public function test_exact_canonical_operation_is_the_pending_current_user_site_settings_link(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('sites', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('component:CurrentUserChip', $operation['route_screen']);
        $this->assertStringContainsString('/sites/', (string) $operation['visible_control']);
        $this->assertStringContainsString('/settings', (string) $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Layout/CurrentUserChip.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);

        $frontend = file_get_contents(resource_path('js/current-user-site-details-control.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('tenantUrl(context.tenant.slug, `/sites/${activeSite.id}/settings`)', $frontend);
    }

    public function test_settings_destination_is_explicit_guarded_and_reads_the_authoritative_tenant_site_without_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Site', 'https://alpha-site.test');

        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/'.$site->id.'/settings', 'GET'));
        $this->assertSame('canonical.site.settings', $route->getName());
        $this->assertSame(SiteSettingsReadController::class, ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant', 'site'], $route->parameterNames());

        $before = Site::query()->withoutGlobalScopes()->findOrFail($site->id)->only(['name', 'url', 'status']);
        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$site->id.'/settings')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('Settings')
            ->assertSee('Alpha Site')
            ->assertSee('https://alpha-site.test')
            ->assertSee('active')
            ->assertSee('No settings mutation is exposed by this canonical navigation control.');
        $after = Site::query()->withoutGlobalScopes()->findOrFail($site->id)->only(['name', 'url', 'status']);

        $this->assertSame($before, $after);
    }

    public function test_guest_missing_permission_and_foreign_site_fail_closed(): void
    {
        $guestTenant = Tenant::query()->create(['name' => 'Guest Tenant', 'slug' => 'guest']);
        $this->get('/tenants/'.$guestTenant->slug.'/sites/1/settings')->assertRedirect();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site', 'https://limited.test');
        $this->actingAs($limited)
            ->get('/tenants/limited/sites/'.$limitedSite->id.'/settings')
            ->assertForbidden();

        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $beta = $this->membership($user, 'beta', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site', 'https://alpha.test');
        $betaSite = $this->site($beta, 'Beta Site', 'https://beta.test');

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$alphaSite->id.'/settings')
            ->assertOk();
        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$betaSite->id.'/settings')
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
        $site = Site::query()->create([
            'name' => $name,
            'url' => $url,
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }
}
