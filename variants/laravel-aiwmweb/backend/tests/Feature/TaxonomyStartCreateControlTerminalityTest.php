<?php

namespace Tests\Feature;

use App\Frontend\ActionContractRegistry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TaxonomyStartCreateControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-TAXO-005925568C';

    public function test_exact_canonical_operation_is_adapted_to_existing_taxonomy_create_dialog_launch(): void
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
        $this->assertSame('taxonomy', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites/{SiteId:guid}/taxonomy', $operation['route_screen']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_real_taxonomy_manage_contract_is_site_scoped_and_content_edit_gated(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'content.view', 'content.edit']);
        $site = $this->site($membership);

        $contracts = app(ActionContractRegistry::class)->contracts(
            $membership->tenant,
            ['tenant.view', 'sites.view', 'content.view', 'content.edit'],
            $site,
        );
        $contract = $contracts['taxonomy.manage'];

        $this->assertSame('enabled', $contract['availability']['state']);
        $this->assertSame('taxonomy.manage', $contract['capability']);
        $this->assertSame('POST', $contract['method']);
        $this->assertSame('/api/v1/tenants/alpha/sites/'.$site->id.'/taxonomy', $contract['endpoint']);
        $this->assertSame('content.edit', $contract['permission']);

        $definition = config('frontend_actions', [])['taxonomy.manage'] ?? null;
        $this->assertIsArray($definition);
        $this->assertSame('site', $definition['ownership']);

        $readOnly = app(ActionContractRegistry::class)->contracts(
            $membership->tenant,
            ['tenant.view', 'sites.view', 'content.view'],
            $site,
        );

        $this->assertSame('permission_denied', $readOnly['taxonomy.manage']['availability']['state']);
    }

    public function test_guest_foreign_tenant_and_foreign_site_fail_closed_before_start_create_can_be_authoritative(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view', 'content.view', 'content.edit']);
        $alphaSite = $this->site($alphaMembership);

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view', 'content.view', 'content.edit']);
        $betaSite = $this->site($betaMembership);

        $this->getJson('/tenants/alpha/context?site='.$alphaSite->id)->assertUnauthorized();

        $this->actingAs($alphaUser)
            ->getJson('/tenants/alpha/context?site='.$betaSite->id)
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->getJson('/tenants/beta/context?site='.$betaSite->id)
            ->assertNotFound();
    }

    public function test_production_marker_is_scoped_to_taxonomy_manage_without_mutating_on_click(): void
    {
        $components = (string) file_get_contents(resource_path('js/components.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/TaxonomyManager.razor'));

        $this->assertStringContainsString(self::OPERATION_ID, $components);
        $this->assertStringContainsString("route.key === 'taxonomy'", $components);
        $this->assertStringContainsString("actionKey === 'taxonomy.manage'", $components);
        $this->assertStringContainsString('onAvailable(contract)', $components);
        $this->assertStringContainsString('route.controls?.map', $pages);
        $this->assertStringContainsString('OnClick="StartCreateClicked"', $source);
        $this->assertStringContainsString('private Task StartCreateClicked', $source);
        $this->assertStringContainsString('CurrentUser.RequirePermission(ApplicationPermissionCatalog.ContentEdit); ResetForm(); return Task.CompletedTask;', $source);
        $this->assertStringNotContainsString(
            'apiRequest',
            substr($components, strpos($components, 'export function ActionButton'), 2600),
        );
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "taxonomy-start-create-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => 'Taxonomy Site',
            'url' => 'https://'.$membership->tenant->slug.'.example.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }
}
