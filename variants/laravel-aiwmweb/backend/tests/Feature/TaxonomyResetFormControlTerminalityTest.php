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

final class TaxonomyResetFormControlTerminalityTest extends TestCase
{
    // Exact-head CI retrigger after official parity materialization.
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-TAXO-5D39DA68B7';

    public function test_exact_canonical_input_is_the_pending_taxonomy_reset_form_control(): void
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
        $this->assertSame('@L[ [ResetFormClicked]', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/TaxonomyManager.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_reset_form_source_and_laravel_adapter_are_local_non_mutating_and_action_scoped(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/TaxonomyManager.razor'));
        $components = (string) file_get_contents(resource_path('js/components.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString('OnClick="ResetFormClicked"', $source);
        $this->assertStringContainsString('private Task ResetFormClicked', $source);
        $this->assertStringContainsString('private void ResetForm()', $source);
        $this->assertStringContainsString('_editingId = 0', $source);
        $this->assertStringContainsString('_model = new()', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $components);
        $this->assertStringContainsString("actionKey === 'taxonomy.manage'", $components);
        $this->assertStringContainsString('const closeAndReset = () =>', $components);
        $this->assertStringContainsString('setValues({});', $components);
        $this->assertStringContainsString('setErrors({});', $components);
        $this->assertStringContainsString('data-canonical-operation={isTaxonomyResetForm ? TAXONOMY_RESET_FORM_OPERATION_ID : undefined}', $components);

        $this->assertStringContainsString('const closeActionDialog = () =>', $pages);
        $this->assertStringContainsString('mutation.reset();', $pages);
        $this->assertStringContainsString('onClose={closeActionDialog}', $pages);

        $resetSegment = substr($components, strpos($components, 'const closeAndReset = () =>'), 500);
        $this->assertStringNotContainsString('fetch(', $resetSegment);
        $this->assertStringNotContainsString('apiRequest', $resetSegment);
        $this->assertStringNotContainsString('onSubmit(', $resetSegment);
    }

    public function test_taxonomy_manage_contract_keeps_site_tenant_authority_and_edit_permission_fail_closed(): void
    {
        $actions = require base_path('config/frontend_actions.php');
        $contract = $actions['taxonomy.manage'] ?? null;

        $this->assertIsArray($contract);
        $this->assertSame('site', $contract['ownership'] ?? null);
        $this->assertSame('content.edit', $contract['permission'] ?? null);
        $this->assertSame('taxonomy', $contract['reconcile_api_key'] ?? null);
        $this->assertSame('taxonomy.manage', $contract['capability'] ?? null);
        $this->assertSame('/api/v1/tenants/{tenant}/sites/{site}/taxonomy', $contract['endpoint'] ?? null);
        $this->assertSame('POST', $contract['method'] ?? null);
    }

    public function test_foreign_tenant_and_foreign_site_fail_closed_before_taxonomy_dialog_can_exist(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view', 'content.view', 'content.edit']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Taxonomy');

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view', 'content.view', 'content.edit']);
        $betaSite = $this->site($betaMembership, 'Beta Taxonomy');

        $this->actingAs($alphaUser)
            ->getJson('/tenants/alpha/context?site='.$alphaSite->id)
            ->assertOk()
            ->assertJsonPath('active_site.id', $alphaSite->id);

        $this->actingAs($alphaUser)
            ->getJson('/tenants/alpha/context?site='.$betaSite->id)
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->getJson('/tenants/beta/context?site='.$betaSite->id)
            ->assertNotFound();
    }

    public function test_terminal_closure_evidence_is_exactly_operation_scoped(): void
    {
        $evidence = json_decode(
            (string) file_get_contents(base_path('../docs/closure-evidence/taxonomy-reset-form-terminality.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(self::OPERATION_ID, $evidence['canonical_operation']['operation_id'] ?? null);
        $this->assertSame('taxonomy', $evidence['canonical_operation']['domain'] ?? null);
        $this->assertSame('visible_control', $evidence['canonical_operation']['kind'] ?? null);
        $this->assertSame('PENDING', $evidence['canonical_operation']['previous_state'] ?? null);
        $this->assertSame('ADAPTED', $evidence['canonical_operation']['terminal_state'] ?? null);
        $this->assertFalse((bool) ($evidence['laravel_adaptation']['mutation_on_click'] ?? true));
        $this->assertSame(self::OPERATION_ID, $evidence['scope_guards']['only_operation_claimed'] ?? null);
        $this->assertTrue((bool) ($evidence['terminality']['focused_tests_present'] ?? false));
        $this->assertTrue((bool) ($evidence['terminality']['evidence_recorded'] ?? false));
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "taxonomy-reset-{$slug}-{$user->id}"]);
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
            'url' => 'https://'.$membership->tenant->slug.'.taxonomy.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }
}
