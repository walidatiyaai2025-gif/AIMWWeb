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

final class ContentHubSiteLinksControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-276925F148';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_matches_both_dynamic_site_links(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/content | /module/content', $operation['route_screen']);
        $this->assertSame('@($ -> @($', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/GlobalContentHub.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);

        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalContentHub.razor'));
        $this->assertStringContainsString('href="@($"/sites/{site.SiteId}")"', $source);
        $this->assertStringContainsString('href="@($"/sites/{site.SiteId}/explorer")"', $source);
    }

    public function test_authoritative_site_read_is_tenant_scoped_and_destinations_are_real_routes(): void
    {
        $apiRoute = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites', 'GET'));
        $this->assertSame(SiteManagementController::class.'@index', ltrim($apiRoute->getActionName(), '\\'));
        $this->assertContains('auth', $apiRoute->gatherMiddleware());
        $this->assertContains('tenant.context', $apiRoute->gatherMiddleware());
        $this->assertSame(['GET', 'HEAD'], $apiRoute->methods());

        $detailsRoute = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/7', 'GET'));
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@showSite', ltrim($detailsRoute->getActionName(), '\\'));
        $this->assertSame('tenant.view,sites.view', $detailsRoute->defaults['workspace_permissions'] ?? null);

        $explorerRoute = Route::getRoutes()->match(Request::create('/tenants/alpha/explorer?site=7', 'GET'));
        $this->assertSame(CanonicalWorkspaceRouteController::class.'@showSiteBound', ltrim($explorerRoute->getActionName(), '\\'));
        $this->assertSame('sites.view', $explorerRoute->defaults['workspace_permissions'] ?? null);

        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', ['tenant.view', 'content.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site');

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['tenant.view', 'content.view', 'sites.view']);
        $this->site($beta, 'Beta Site');

        $this->actingAs($alphaUser)
            ->getJson('/api/tenants/alpha/sites')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $alphaSite->id)
            ->assertJsonPath('0.name', 'Alpha Site')
            ->assertJsonMissing(['name' => 'Beta Site']);

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/sites/'.$alphaSite->id)
            ->assertOk();

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/explorer?site='.$alphaSite->id)
            ->assertOk();
    }

    public function test_guest_permission_and_foreign_tenant_fail_closed(): void
    {
        $this->get('/tenants/alpha/content')->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view', 'sites.view']);
        $this->actingAs($limited)->get('/tenants/limited/content')->assertForbidden();

        $alphaUser = User::factory()->create();
        $this->membership($alphaUser, 'alpha', ['tenant.view', 'content.view', 'sites.view']);

        $betaUser = User::factory()->create();
        $this->membership($betaUser, 'beta', ['tenant.view', 'content.view', 'sites.view']);

        $this->actingAs($alphaUser)->get('/tenants/beta/content')->assertNotFound();
        $this->actingAs($alphaUser)->getJson('/api/tenants/beta/sites')->assertNotFound();
    }

    public function test_runtime_control_is_exactly_operation_linked_and_read_only(): void
    {
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $control = (string) file_get_contents(resource_path('js/content-hub-site-links-control.tsx'));

        $this->assertStringContainsString("import { ContentHubSiteLinksControl } from './content-hub-site-links-control';", $app);
        $this->assertStringContainsString('<ContentHubSiteLinksControl context={context} />', $app);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("apiRequest<unknown>(endpoint!)", $control);
        $this->assertStringContainsString("context.api.sites", $control);
        $this->assertStringContainsString("sitesInterpolatedDestinations(context.tenant.slug, site.id)", $control);
        $this->assertStringContainsString("data-source-control=\"site-details\"", $control);
        $this->assertStringContainsString("data-source-control=\"site-explorer\"", $control);
        $this->assertStringNotContainsString("method: 'POST'", $control);
        $this->assertStringNotContainsString("method: 'PUT'", $control);
        $this->assertStringNotContainsString("method: 'PATCH'", $control);
        $this->assertStringNotContainsString("method: 'DELETE'", $control);
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "content-site-links-{$slug}-{$user->id}"]);

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
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test',
            'status' => 'active',
        ]);

        $context->forget();

        return $site;
    }
}
