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

final class SitesOpenControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-EE659E3F13';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_source_semantics_are_the_sites_open_button_to_the_dynamic_site_dashboard(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Sites.razor'),
        );

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites', $operation['route_screen']);
        $this->assertSame('@L[ -> @($', $operation['visible_control']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertStringContainsString(
            '<AppButton Href="@($"/sites/{site.Id}")" Variant="primary" Text="@L["Open"]" />',
            $source,
        );
    }

    public function test_laravel_reuses_the_existing_authoritative_site_row_destination_for_open(): void
    {
        $control = (string) file_get_contents(resource_path('js/sites-interpolated-links-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('data-canonical-operation={SITES_OPEN_OPERATION_ID}', $control);
        $this->assertStringContainsString('tenantUrl(tenantSlug, `/sites/${normalizedSiteId}`)', $control);
        $this->assertStringContainsString('<SitesInterpolatedLinksControl tenantSlug={context.tenant.slug} siteId={row.id} />', $pages);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
    }

    public function test_authorized_open_renders_real_site_details_without_mutating_site(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Alpha Site');
        $before = Site::withoutGlobalScopes()->findOrFail($site->id)->toArray();

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$site->id)
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->assertSame($before, Site::withoutGlobalScopes()->findOrFail($site->id)->toArray());
    }

    public function test_foreign_tenant_and_site_open_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site');

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view']);
        $betaSite = $this->site($beta, 'Beta Site');

        $this->actingAs($alphaUser)->get('/tenants/alpha/sites/'.$alphaSite->id)->assertOk();
        $this->actingAs($alphaUser)->get('/tenants/alpha/sites/'.$betaSite->id)->assertNotFound();
        $this->actingAs($alphaUser)->get('/tenants/beta/sites/'.$betaSite->id)->assertNotFound();
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
        $role = Role::query()->create(['name' => "sites-open-{$slug}-{$user->id}"]);

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
