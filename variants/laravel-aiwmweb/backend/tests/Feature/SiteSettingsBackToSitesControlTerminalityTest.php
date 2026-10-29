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

final class SiteSettingsBackToSitesControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-3D6485D903';

    public function test_source_and_laravel_bind_the_exact_back_to_sites_navigation(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteSettings.razor'),
        );
        $view = (string) file_get_contents(resource_path('views/sites/settings.blade.php'));

        $this->assertStringContainsString('Href="/sites"', $source);
        $this->assertStringContainsString('Back to sites', $source);
        $this->assertStringContainsString('العودة للمواقع', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringContainsString(
            "route('canonical.workspace.sites', ['tenant' => \$tenant], false)",
            $view,
        );
        $this->assertSame(1, substr_count($view, 'data-canonical-operation="'.self::OPERATION_ID.'"'));
    }

    public function test_source_and_destination_are_real_guarded_routes(): void
    {
        $source = Route::getRoutes()->match(
            Request::create('/tenants/alpha/sites/123/settings', 'GET'),
        );
        $destination = Route::getRoutes()->match(
            Request::create('/tenants/alpha/sites', 'GET'),
        );

        $this->assertSame('canonical.site.settings', $source->getName());
        $this->assertSame(SiteSettingsReadController::class, ltrim($source->getActionName(), '\\'));
        $this->assertContains('auth', $source->gatherMiddleware());
        $this->assertContains('tenant.context', $source->gatherMiddleware());

        $this->assertSame('canonical.workspace.sites', $destination->getName());
        $this->assertContains('auth', $destination->gatherMiddleware());
        $this->assertContains('tenant.context', $destination->gatherMiddleware());
        $this->assertSame('tenant.view,sites.view', $destination->defaults['workspace_permissions'] ?? null);
        $this->assertSame(['tenant'], $destination->parameterNames());
    }

    public function test_authorized_navigation_is_tenant_derived_read_only_and_reaches_real_sites_workspace(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Site', 'https://alpha-site.test');
        $this->withoutVite();

        $before = Site::query()->withoutGlobalScopes()->findOrFail($site->id)
            ->only(['tenant_id', 'name', 'url', 'status', 'connection_status']);

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$site->id.'/settings')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('href="/tenants/alpha/sites"', false)
            ->assertSee('Back to sites');

        $this->actingAs($user)
            ->get('/tenants/alpha/sites')
            ->assertOk();

        $after = Site::query()->withoutGlobalScopes()->findOrFail($site->id)
            ->only(['tenant_id', 'name', 'url', 'status', 'connection_status']);

        $this->assertSame($before, $after);
    }

    public function test_back_to_sites_label_preserves_source_bilingual_locale_semantics(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'bilingual', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Bilingual Site', 'https://bilingual.test');
        $this->withoutVite();

        $this->app->setLocale('ar');
        $this->actingAs($user)
            ->get('/tenants/bilingual/sites/'.$site->id.'/settings')
            ->assertOk()
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('العودة للمواقع');

        $this->app->setLocale('en');
        $this->actingAs($user)
            ->get('/tenants/bilingual/sites/'.$site->id.'/settings')
            ->assertOk()
            ->assertSee('Back to sites');
    }

    public function test_guest_missing_permission_foreign_site_and_foreign_tenant_fail_closed(): void
    {
        $guestTenant = Tenant::query()->create(['name' => 'Guest Tenant', 'slug' => 'guest']);
        $this->get('/tenants/'.$guestTenant->slug.'/sites/1/settings')->assertRedirect();
        $this->get('/tenants/'.$guestTenant->slug.'/sites')->assertRedirect();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site', 'https://limited.test');

        $this->actingAs($limited)
            ->get('/tenants/limited/sites/'.$limitedSite->id.'/settings')
            ->assertForbidden();
        $this->actingAs($limited)
            ->get('/tenants/limited/sites')
            ->assertForbidden();

        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site', 'https://alpha.test');

        $otherUser = User::factory()->create();
        $beta = $this->membership($otherUser, 'beta', ['tenant.view', 'sites.view']);
        $betaSite = $this->site($beta, 'Beta Site', 'https://beta.test');

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$alphaSite->id.'/settings')
            ->assertOk();
        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$betaSite->id.'/settings')
            ->assertNotFound();
        $this->actingAs($user)
            ->get('/tenants/beta/sites')
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create([
            'name' => "site-settings-back-to-sites-{$slug}-{$user->id}",
        ]);

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
