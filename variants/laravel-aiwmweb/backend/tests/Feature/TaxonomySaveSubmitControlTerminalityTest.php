<?php

namespace Tests\Feature;

use App\Frontend\ActionContractRegistry;
use App\Models\Site;
use App\Models\Tenant;
use Tests\TestCase;

final class TaxonomySaveSubmitControlTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-TAXO-39CDD7EF81';

    public function test_exact_canonical_input_is_the_pending_taxonomy_save_submit_control(): void
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
        $this->assertSame('@(_saving ? L[', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/TaxonomyManager.razor',
            $operation['current_source'],
        );
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_source_and_laravel_adapter_bind_the_real_save_submit_semantics(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/TaxonomyManager.razor'),
        );
        $components = (string) file_get_contents(resource_path('js/components.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString('OnValidSubmit="SaveAsync"', $source);
        $this->assertStringContainsString('Text="@(_saving ? L["Saving"] : L["Save"])"', $source);
        $this->assertStringContainsString('private async Task SaveAsync()', $source);
        $this->assertStringContainsString('ApplicationPermissionCatalog.ContentEdit', $source);
        $this->assertStringContainsString('TaxonomyService.UpdateAsync', $source);
        $this->assertStringContainsString('TaxonomyService.CreateAsync', $source);
        $this->assertStringContainsString('await LoadAsync()', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $components);
        $this->assertStringContainsString("actionKey === 'taxonomy.manage'", $components);
        $this->assertStringContainsString(
            'data-canonical-operation={isTaxonomySaveSubmit ? TAXONOMY_SAVE_SUBMIT_OPERATION_ID : undefined}',
            $components,
        );
        $this->assertStringContainsString('type="submit"', $components);
        $this->assertStringContainsString("locale === 'ar' ? 'حفظ' : 'Save'", $components);
        $this->assertStringContainsString("locale === 'ar' ? 'جارٍ الحفظ' : 'Saving'", $components);

        $this->assertStringContainsString('mutateThenReconcile(', $pages);
        $this->assertStringContainsString('apiRequest(request.endpoint', $pages);
        $this->assertStringContainsString('const refreshed = await query.refetch()', $pages);
        $this->assertStringContainsString('busy={mutation.isPending}', $pages);
    }

    public function test_taxonomy_manage_contract_is_site_owned_post_and_content_edit_gated(): void
    {
        $contract = config('frontend_actions', [])['taxonomy.manage'] ?? null;

        $this->assertIsArray($contract);
        $this->assertSame('site', $contract['ownership'] ?? null);
        $this->assertSame('content.edit', $contract['permission'] ?? null);
        $this->assertSame('taxonomy.manage', $contract['capability'] ?? null);
        $this->assertSame('/api/v1/tenants/{tenant}/sites/{site}/taxonomy', $contract['endpoint'] ?? null);
        $this->assertSame('POST', $contract['method'] ?? null);
        $this->assertSame('taxonomy', $contract['reconcile_api_key'] ?? null);
    }

    public function test_contract_discovery_fails_closed_for_permission_foreign_tenant_and_missing_site_context(): void
    {
        $tenant = new Tenant(['name' => 'Alpha', 'slug' => 'alpha']);
        $tenant->id = 10;

        $ownedSite = new Site(['name' => 'Owned', 'url' => 'https://alpha.example.test']);
        $ownedSite->id = 17;
        $ownedSite->tenant_id = 10;

        $foreignSite = new Site(['name' => 'Foreign', 'url' => 'https://beta.example.test']);
        $foreignSite->id = 44;
        $foreignSite->tenant_id = 99;

        $registry = app(ActionContractRegistry::class);

        $allowed = $registry->contracts($tenant, ['tenant.view', 'content.edit'], $ownedSite);
        $this->assertSame('enabled', $allowed['taxonomy.manage']['availability']['state']);

        $permissionDenied = $registry->contracts($tenant, ['tenant.view'], $ownedSite);
        $this->assertSame('permission_denied', $permissionDenied['taxonomy.manage']['availability']['state']);

        $foreign = $registry->contracts($tenant, ['*'], $foreignSite);
        $this->assertSame('context_mismatch', $foreign['taxonomy.manage']['availability']['state']);

        $withoutSite = $registry->contracts($tenant, ['*']);
        $this->assertSame('site_context_required', $withoutSite['taxonomy.manage']['availability']['state']);

        // The shared HTTP boundary proves the same missing-permission path with assertForbidden / 403
        // and cross-tenant or foreign-site direct IDs with assertNotFound / 404 in ActionContractClosureTest.
        $this->assertNotEmpty($foreign);
    }

    public function test_terminal_closure_evidence_is_exactly_operation_scoped(): void
    {
        $evidence = json_decode(
            (string) file_get_contents(base_path('../docs/closure-evidence/taxonomy-save-submit-terminality.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(self::OPERATION_ID, $evidence['canonical_operation']['operation_id'] ?? null);
        $this->assertSame('taxonomy', $evidence['canonical_operation']['domain'] ?? null);
        $this->assertSame('visible_control', $evidence['canonical_operation']['kind'] ?? null);
        $this->assertSame('PENDING', $evidence['canonical_operation']['previous_state'] ?? null);
        $this->assertSame('ADAPTED', $evidence['canonical_operation']['terminal_state'] ?? null);
        $this->assertSame(self::OPERATION_ID, $evidence['scope_guards']['only_operation_claimed'] ?? null);
        $this->assertTrue((bool) ($evidence['terminality']['runtime_wired'] ?? false));
        $this->assertTrue((bool) ($evidence['terminality']['focused_tests_present'] ?? false));
    }
}
