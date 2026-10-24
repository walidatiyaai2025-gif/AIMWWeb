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

final class TaxonomyBackToSitesControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-TAXO-770DAA668D';

    public function test_exact_canonical_input_is_the_pending_taxonomy_back_to_sites_control(): void
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
        $this->assertSame('taxonomy', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites/{SiteId:guid}/taxonomy', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/TaxonomyManager.razor', $operation['current_source']);
        $this->assertSame('@L[ -> /sites', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_materialized_reconciliation_terminalizes_only_the_claimed_operation(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('focused_closure_contract', $operation['reconciliation']['evidence_mode'] ?? null);
    }

    public function test_sites_destination_is_real_tenant_guarded_read_only_workspace(): void
    {
        $destination = Route::getRoutes()->match(Request::create('/tenants/alpha/sites', 'GET'));

        $this->assertSame(
            CanonicalWorkspaceRouteController::class.'@show',
            ltrim($destination->getActionName(), '\\'),
        );
        $this->assertSame('tenant.view,sites.view', $destination->defaults['workspace_permissions'] ?? null);
        $this->assertContains('auth', $destination->gatherMiddleware());
        $this->assertContains('tenant.context', $destination->gatherMiddleware());
    }

    public function test_missing_site_context_can_recover_to_authoritative_sites_without_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'content.view']);
        $site = $this->site($membership, 'Alpha Site');
        $before = Site::withoutGlobalScopes()->findOrFail($site->id)->toArray();
        $this->withoutVite();

        $this->actingAs($user)
            ->getJson('/tenants/alpha/context')
            ->assertOk()
            ->assertJsonPath('tenant.slug', 'alpha')
            ->assertJsonPath('active_site', null);

        $this->actingAs($user)
            ->get('/tenants/alpha/sites')
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($user)
            ->getJson('/api/tenants/alpha/sites')
            ->assertOk()
            ->assertJsonFragment(['id' => $site->id, 'name' => 'Alpha Site']);

        $after = Site::withoutGlobalScopes()->findOrFail($site->id)->toArray();
        $this->assertSame($before, $after, 'Back to sites recovery navigation must not mutate Site persistence.');
    }

    public function test_guest_missing_sites_permission_foreign_tenant_and_foreign_site_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view', 'content.view']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site');

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view', 'content.view']);
        $betaSite = $this->site($betaMembership, 'Beta Site');

        $this->getJson('/tenants/alpha/context')->assertUnauthorized();
        $this->get('/tenants/alpha/sites')->assertRedirect('/login');

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view', 'content.view']);
        $this->site($limitedMembership, 'Limited Site');
        $this->actingAs($limited)->get('/tenants/limited/sites')->assertForbidden();

        $this->actingAs($alphaUser)
            ->getJson('/tenants/alpha/context?site='.$betaSite->id)
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->get('/tenants/beta/sites')
            ->assertNotFound();

        $this->assertDatabaseHas('sites', ['id' => $alphaSite->id, 'tenant_id' => $alphaMembership->tenant_id]);
        $this->assertDatabaseHas('sites', ['id' => $betaSite->id, 'tenant_id' => $betaMembership->tenant_id]);
    }

    public function test_production_wiring_matches_the_source_missing_site_recovery_control(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/TaxonomyManager.razor'));
        $component = (string) file_get_contents(resource_path('js/taxonomy-back-to-sites-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('else if (_site is null)', $source);
        $this->assertStringContainsString('Href="/sites"', $source);
        $this->assertStringContainsString('BackToSites', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $component);
        $this->assertStringContainsString('active_site', $component);
        $this->assertStringContainsString("context.permissions.includes('sites.view')", $component);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/sites')", $component);
        $this->assertStringContainsString('TaxonomyBackToSitesControl', $app);
        $this->assertStringContainsString("route.key === 'taxonomy'", $app);
        $this->assertStringNotContainsString('apiRequest', $component);
        $this->assertStringNotContainsString('fetch(', $component);
        $this->assertStringNotContainsString('method:', $component);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "taxonomy-back-sites-{$slug}-{$user->id}"]);
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
