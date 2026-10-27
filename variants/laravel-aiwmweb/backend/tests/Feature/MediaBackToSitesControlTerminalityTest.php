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

final class MediaBackToSitesControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-MEDI-34B5C789D2';

    public function test_exact_canonical_input_is_the_pending_media_back_to_sites_control(): void
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
        $this->assertSame('media', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites/{SiteId:guid}/media', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/MediaManager.razor', $operation['current_source']);
        $this->assertSame('@L[ -> /sites', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_source_and_destination_use_explicit_tenant_guarded_routes(): void
    {
        $source = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/1/media', 'GET'));
        $destination = Route::getRoutes()->match(Request::create('/tenants/alpha/sites', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@redirectSite',
            ltrim($source->getActionName(), '\\'),
        );
        $this->assertSame('content.view', $source->defaults['workspace_permissions'] ?? null);
        $this->assertSame('/module/media', $source->defaults['workspace_target'] ?? null);
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

    public function test_authorized_navigation_reaches_real_media_and_sites_workspaces_without_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'content.view']);
        $site = $this->site($membership, 'Alpha Site');
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$site->id.'/media')
            ->assertRedirect('/tenants/alpha/module/media?site='.$site->id);

        $this->actingAs($user)
            ->get('/tenants/alpha/module/media?site='.$site->id)
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

        $this->assertDatabaseHas('sites', [
            'id' => $site->id,
            'tenant_id' => $membership->tenant_id,
            'name' => 'Alpha Site',
        ]);
    }

    public function test_missing_site_and_foreign_site_reads_fail_closed_with_authoritative_404(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view', 'content.view']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site');

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view', 'content.view']);
        $betaSite = $this->site($betaMembership, 'Beta Site');

        $beforeSites = Site::withoutGlobalScopes()->count();

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/context?site=999999')
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/sites/'.$betaSite->id.'/media')
            ->assertNotFound();

        $this->assertSame($beforeSites, Site::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('sites', ['id' => $alphaSite->id, 'tenant_id' => $alphaMembership->tenant_id]);
        $this->assertDatabaseHas('sites', ['id' => $betaSite->id, 'tenant_id' => $betaMembership->tenant_id]);
    }

    public function test_guest_missing_permission_and_foreign_tenant_destination_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view', 'content.view']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site');
        $this->withoutVite();

        $this->get('/tenants/alpha/sites/'.$alphaSite->id.'/media')->assertRedirect('/login');
        $this->get('/tenants/alpha/sites')->assertRedirect('/login');

        $contentLimited = User::factory()->create();
        $contentLimitedMembership = $this->membership($contentLimited, 'content-limited', ['tenant.view', 'sites.view']);
        $contentLimitedSite = $this->site($contentLimitedMembership, 'Content Limited Site');
        $this->actingAs($contentLimited)
            ->get('/tenants/content-limited/sites/'.$contentLimitedSite->id.'/media')
            ->assertForbidden();

        $sitesLimited = User::factory()->create();
        $sitesLimitedMembership = $this->membership($sitesLimited, 'sites-limited', ['tenant.view', 'content.view']);
        $this->site($sitesLimitedMembership, 'Sites Limited Site');
        $this->actingAs($sitesLimited)->get('/tenants/sites-limited/sites')->assertForbidden();

        $this->actingAs($alphaUser)->get('/tenants/beta/sites')->assertNotFound();
    }

    public function test_production_wiring_preserves_not_found_only_semantics(): void
    {
        $component = (string) file_get_contents(resource_path('js/media-back-to-sites-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $component);
        $this->assertStringContainsString('error instanceof ApiError && error.status === 404', $component);
        $this->assertStringContainsString("tenantUrl(tenantSlug, '/sites')", $component);
        $this->assertStringContainsString('data-testid="media-back-to-sites"', $component);

        $this->assertStringContainsString("location.pathname.endsWith('/module/media')", $app);
        $this->assertStringContainsString('mediaTenantSlug={isMediaWorkspace ? tenantSlug : null}', $app);
        $this->assertStringContainsString('<MediaBackToSitesControl tenantSlug={mediaTenantSlug} error={error} />', $app);
        $this->assertStringNotContainsString('<MediaBackToSitesControl context={context}', $app);

        $this->assertStringContainsString("route.key === 'media'", $pages);
        $this->assertStringContainsString(
            '<MediaBackToSitesControl tenantSlug={context.tenant.slug} error={query.error} />',
            $pages,
        );

        $this->assertStringNotContainsString('fetch(', $component);
        $this->assertStringNotContainsString('apiRequest', $component);
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "media-back-{$slug}-{$user->id}"]);
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
