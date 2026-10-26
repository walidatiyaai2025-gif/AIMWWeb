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
use Tests\TestCase;

final class ModuleWorkspaceDynamicSiteLinksTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-E4D7C73D4E';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_is_the_module_workspace_dynamic_site_link_control(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/module/{Key}', $operation['route_screen']);
        $this->assertSame('@($ -> @($', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/ModuleWorkspace.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_source_dynamic_links_are_adapted_to_real_tenant_and_site_destinations(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ModuleWorkspace.razor'),
        );
        $control = (string) file_get_contents(resource_path('js/module-workspace-load-sites-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('href="@($"/sites/{site.Id}/seo")"', $source);
        $this->assertStringContainsString('href="@($"/sites/{site.Id}/explorer")"', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('data-testid="seo-site-link"', $control);
        $this->assertStringContainsString('data-testid="seo-content-explorer-link"', $control);
        $this->assertStringContainsString('tenantUrl(context.tenant.slug, `/sites/${site.id}/seo`)', $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/explorer')", $control);
        $this->assertStringContainsString('encodeURIComponent(String(site.id))', $control);
        $this->assertStringContainsString(
            '<ModuleWorkspaceLoadSitesControl context={context} route={route} />',
            $app,
        );
    }

    public function test_authorized_dynamic_destinations_render_real_workspaces_without_mutating_site(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'seo.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Site');
        $before = Site::withoutGlobalScopes()->findOrFail($site->id)->toArray();

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$site->id.'/seo')
            ->assertOk();

        $this->actingAs($user)
            ->get('/tenants/alpha/explorer?site='.$site->id)
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->assertSame($before, Site::withoutGlobalScopes()->findOrFail($site->id)->toArray());
    }

    public function test_foreign_tenant_and_site_destinations_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', ['tenant.view', 'seo.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site');

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['tenant.view', 'seo.view', 'sites.view']);
        $betaSite = $this->site($beta, 'Beta Site');

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/sites/'.$alphaSite->id.'/seo')
            ->assertOk();

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/sites/'.$betaSite->id.'/seo')
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/explorer?site='.$betaSite->id)
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->get('/tenants/beta/explorer?site='.$betaSite->id)
            ->assertNotFound();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "module-dynamic-links-{$slug}-{$user->id}"]);

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

        try {
            return Site::query()->create([
                'name' => $name,
                'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test',
                'status' => 'active',
            ]);
        } finally {
            $context->forget();
        }
    }
}
