<?php

namespace Tests\Feature;

use App\Http\Controllers\SeoVisibleControlController;
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

final class SeoManagerRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SEO-5F71B89C92';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_manager_route_is_explicit_and_bound_to_the_exact_canonical_operation(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/7/seo', 'GET'));

        $this->assertSame('canonical.site.seo', $route->getName());
        $this->assertSame(SeoVisibleControlController::class.'@manager', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_authorized_member_renders_real_tenant_and_site_bound_seo_manager(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'seo.view']);
        $site = $this->site($membership, 'Alpha SEO', 'https://alpha.test');

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$site->id.'/seo')
            ->assertOk()
            ->assertViewIs('seo.manager')
            ->assertViewHas('tenant', 'alpha')
            ->assertViewHas('site', fn (Site $resolved): bool => $resolved->is($site))
            ->assertViewHas('config', static function (array $config) use ($site): bool {
                return ($config['tenant'] ?? null) === 'alpha'
                    && ($config['site']['id'] ?? null) === $site->id
                    && ($config['site']['name'] ?? null) === 'Alpha SEO'
                    && ($config['urls']['audits'] ?? null) === '/api/tenants/alpha/sites/'.$site->id.'/seo/audits'
                    && ($config['urls']['presentation'] ?? null) === '/tenants/alpha/sites/'.$site->id.'/seo/presentation'
                    && ($config['urls']['execution'] ?? null) === '/tenants/alpha/module/execution'
                    && ($config['urls']['sites'] ?? null) === '/tenants/alpha/sites'
                    && ($config['urls']['explorer'] ?? null) === '/tenants/alpha/module/posts?site='.$site->id
                    && ($config['urls']['approvals'] ?? null) === '/tenants/alpha/approvals';
            })
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false);
    }

    public function test_guest_missing_permission_and_foreign_tenant_or_site_fail_closed(): void
    {
        $guestTenant = Tenant::query()->create(['name' => 'Guest', 'slug' => 'guest']);
        $this->get('/tenants/'.$guestTenant->slug.'/sites/1/seo')->assertRedirect();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited SEO', 'https://limited.test');
        $this->actingAs($limited)
            ->get('/tenants/limited/sites/'.$limitedSite->id.'/seo')
            ->assertForbidden();

        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'seo.view']);
        $beta = $this->membership($user, 'beta', ['tenant.view', 'seo.view']);
        $alphaSite = $this->site($alpha, 'Alpha', 'https://alpha.test');
        $betaSite = $this->site($beta, 'Beta', 'https://beta.test');

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$alphaSite->id.'/seo')
            ->assertOk();
        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$betaSite->id.'/seo')
            ->assertNotFound();

        $foreignOnly = User::factory()->create();
        $this->membership($foreignOnly, 'owned', ['tenant.view', 'seo.view']);
        $this->actingAs($foreignOnly)
            ->get('/tenants/beta/sites/'.$betaSite->id.'/seo')
            ->assertNotFound();
    }

    public function test_manager_get_is_read_only_and_does_not_manufacture_seo_state(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'seo.view']);
        $site = $this->site($membership, 'Alpha SEO', 'https://alpha.test');

        $beforeSites = Site::withoutGlobalScopes()->count();
        $beforeMemberships = TenantMembership::withoutGlobalScopes()->count();

        $response = $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$site->id.'/seo')
            ->assertOk();

        $this->assertSame($beforeSites, Site::withoutGlobalScopes()->count());
        $this->assertSame($beforeMemberships, TenantMembership::withoutGlobalScopes()->count());
        $response->assertDontSee('sample finding', false)
            ->assertDontSee('synthetic success', false);
    }

    public function test_existing_batch_regression_evidence_still_names_this_exact_operation(): void
    {
        $provider = file_get_contents(app_path('Providers/SeoVisibleControlRouteServiceProvider.php'));
        $controller = file_get_contents(app_path('Http/Controllers/SeoVisibleControlController.php'));
        $view = file_get_contents(resource_path('views/seo/manager.blade.php'));
        $evidence = file_get_contents(base_path('../docs/closure-evidence/seo-visible-control-mass-closure.json'));

        $this->assertStringContainsString(self::OPERATION_ID, $provider);
        $this->assertStringContainsString('function manager(', $controller);
        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringContainsString(self::OPERATION_ID, $evidence);
        $this->assertStringContainsString('/tenants/{tenant}/sites/{site}/seo', $evidence);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "seo-manager-{$slug}-{$user->id}"]);

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
        $site = Site::query()->create(['name' => $name, 'url' => $url, 'status' => 'active']);
        $context->forget();

        return $site;
    }
}
