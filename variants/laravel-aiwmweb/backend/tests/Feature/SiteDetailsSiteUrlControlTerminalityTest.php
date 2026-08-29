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

class SiteDetailsSiteUrlControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-A8D10964C6';

    public function test_exact_canonical_operation_is_the_pending_site_details_site_url_control(): void
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
        $this->assertSame('@_site.SiteUrl -> @_site.SiteUrl', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_site_details_workspace_and_read_api_are_real_tenant_scoped_routes(): void
    {
        $workspace = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/1', 'GET'));
        $api = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/1', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@showSite',
            ltrim($workspace->getActionName(), '\\'),
        );
        $this->assertSame('tenant.view,sites.view', $workspace->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $workspace->gatherMiddleware());
        $this->assertContains('tenant.context', $workspace->gatherMiddleware());

        $this->assertSame(
            SiteManagementController::class.'@show',
            ltrim($api->getActionName(), '\\'),
        );
        $this->assertContains('auth', $api->gatherMiddleware());
        $this->assertContains('tenant.context', $api->gatherMiddleware());
    }

    public function test_authorized_site_details_context_reads_the_persisted_site_url_without_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Site', 'https://alpha.example.test');
        $siteCount = Site::query()->withoutGlobalScopes()->count();
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$site->id)
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->getJson('/tenants/alpha/context')
            ->assertOk()
            ->assertJsonPath('api.sites.detail.'.$site->id, "/api/tenants/alpha/sites/{$site->id}");

        $this->actingAs($user)
            ->getJson('/api/tenants/alpha/sites/'.$site->id)
            ->assertOk()
            ->assertJsonPath('id', $site->id)
            ->assertJsonPath('name', 'Alpha Site')
            ->assertJsonPath('url', 'https://alpha.example.test');

        $this->assertSame($siteCount, Site::query()->withoutGlobalScopes()->count());
        $this->assertSame('https://alpha.example.test', Site::query()->withoutGlobalScopes()->findOrFail($site->id)->url);
    }

    public function test_foreign_site_and_caller_supplied_url_probes_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site', 'https://alpha.example.test');

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view']);
        $betaSite = $this->site($betaMembership, 'Beta Site', 'https://beta.example.test');
        $this->withoutVite();

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/sites/'.$betaSite->id)
            ->assertNotFound();
        $this->actingAs($alphaUser)
            ->getJson('/api/tenants/alpha/sites/'.$betaSite->id)
            ->assertNotFound();
        $this->actingAs($alphaUser)
            ->getJson('/api/tenants/beta/sites/'.$alphaSite->id)
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->getJson('/api/tenants/alpha/sites/'.$alphaSite->id.'?url=https%3A%2F%2Fattacker.example')
            ->assertOk()
            ->assertJsonPath('url', 'https://alpha.example.test');
    }

    public function test_guest_and_missing_workspace_permission_cannot_render_the_control_surface(): void
    {
        $owner = User::factory()->create();
        $ownerMembership = $this->membership($owner, 'alpha', ['tenant.view', 'sites.view']);
        $ownerSite = $this->site($ownerMembership, 'Alpha Site', 'https://alpha.example.test');
        $this->withoutVite();

        $this->get('/tenants/alpha/sites/'.$ownerSite->id)->assertRedirect('/login');

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site', 'https://limited.example.test');

        $this->actingAs($limited)
            ->get('/tenants/limited/sites/'.$limitedSite->id)
            ->assertForbidden();
    }

    public function test_frontend_control_is_bound_only_to_the_authoritative_site_details_read_contract(): void
    {
        $control = (string) file_get_contents(resource_path('js/site-details-site-url-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString("SITE_DETAILS_SITE_URL_OPERATION_ID = '".self::OPERATION_ID."'", $control);
        $this->assertStringContainsString('context.api[`sites.detail.${siteId}`]', $control);
        $this->assertStringContainsString('apiRequest<SiteUrlPayload>(endpoint!)', $control);
        $this->assertStringContainsString("parsed.protocol !== 'http:' && parsed.protocol !== 'https:'", $control);
        $this->assertStringContainsString('target="_blank" rel="noopener noreferrer"', $control);
        $this->assertStringNotContainsString('window.location.search', $control);
        $this->assertStringNotContainsString('useMutation', $control);
        $this->assertStringContainsString('<SiteDetailsSiteUrlControl context={context} />', $app);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "site-url-{$slug}-{$user->id}"]);
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
