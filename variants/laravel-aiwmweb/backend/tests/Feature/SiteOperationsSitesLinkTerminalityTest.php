<?php

namespace Tests\Feature;

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
use Illuminate\Support\Str;
use Tests\TestCase;

final class SiteOperationsSitesLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-5BE26C7AB5';

    public function test_source_and_laravel_bind_the_exact_manage_sites_navigation_across_render_states(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteOperationsOverview.razor'),
        );
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $control = (string) file_get_contents(resource_path('js/site-operations-sites-link-control.tsx'));

        $this->assertSame(2, substr_count($source, 'href="/sites"'));
        $this->assertStringContainsString('Manage sites', $source);
        $this->assertStringContainsString('إدارة المواقع', $source);
        $this->assertStringContainsString('if (_loading)', $source);
        $this->assertStringContainsString('else if (!string.IsNullOrWhiteSpace(_loadError))', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/sites')", $control);
        $this->assertStringContainsString('Manage sites', $control);
        $this->assertStringContainsString('إدارة المواقع', $control);

        // Source hides this control while loading, renders it on load failure, and renders it in normal/empty states.
        $this->assertSame(2, substr_count($pages, '<SiteOperationsSitesLinkControl context={context} />'));
        $loadingPosition = strpos($pages, 'if (query.isLoading) return <LoadingState />;');
        $errorPosition = strpos($pages, 'if (query.error) return (');
        $firstControlPosition = strpos($pages, '<SiteOperationsSitesLinkControl context={context} />');
        $this->assertIsInt($loadingPosition);
        $this->assertIsInt($errorPosition);
        $this->assertIsInt($firstControlPosition);
        $this->assertLessThan($firstControlPosition, $errorPosition);
    }

    public function test_destination_is_the_real_guarded_tenant_sites_workspace(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/sites', 'GET'));

        $this->assertSame('canonical.workspace.sites', $route->getName());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame('tenant.view,sites.view', $route->defaults['workspace_permissions'] ?? null);
        $this->assertSame(['tenant'], $route->parameterNames());
    }

    public function test_authorized_destination_is_read_only_tenant_scoped_and_foreign_tenant_fails_closed(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha-site-ops-link', ['tenant.view', 'sites.view', 'execution.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site Operations Link');
        $this->withoutVite();

        $foreignOwner = User::factory()->create();
        $beta = $this->membership($foreignOwner, 'beta-site-ops-link', ['tenant.view', 'sites.view', 'execution.view']);
        $this->site($beta, 'Beta Site Operations Link');

        $before = Site::query()->withoutGlobalScopes()->count();

        $this->actingAs($user)
            ->get('/tenants/alpha-site-ops-link/sites')
            ->assertOk();

        $this->actingAs($user)
            ->getJson('/api/tenants/alpha-site-ops-link/sites')
            ->assertOk()
            ->assertJsonFragment(['name' => $alphaSite->name])
            ->assertJsonMissing(['name' => 'Beta Site Operations Link']);

        $this->actingAs($user)
            ->get('/tenants/beta-site-ops-link/sites')
            ->assertNotFound();

        $this->assertSame($before, Site::query()->withoutGlobalScopes()->count());
    }

    public function test_guest_and_missing_sites_permission_fail_closed(): void
    {
        $guestTenant = Tenant::query()->create(['name' => 'Guest Site Ops Link', 'slug' => 'guest-site-ops-link']);
        $this->get('/tenants/'.$guestTenant->slug.'/sites')->assertRedirect();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited-site-ops-link', ['tenant.view', 'execution.view']);

        $this->actingAs($limited)
            ->get('/tenants/limited-site-ops-link/sites')
            ->assertForbidden();
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => Str::headline($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => $slug.'-role-'.$user->id]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }

    private function site(Tenant $tenant, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.Str::slug($name).'.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }
}
