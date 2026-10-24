<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SitesCloseFormTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-CONT-FBA95BDC1D';

    public function test_exact_canonical_operation_matches_sites_close_form(): void
    {
        $ledger = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites', $operation['route_screen']);
        $this->assertSame('Close [CloseForm]', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/Sites.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_laravel_preserves_close_form_local_state_semantics_without_request_or_mutation(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/Sites.razor'));
        $frontend = (string) file_get_contents(resource_path('js/sites-save-control.tsx'));

        $this->assertStringContainsString('@onclick="CloseForm"', $source);
        $this->assertStringContainsString('private void CloseForm() { if (_saving) return; _showForm = false; _editingId = null; _model = new(); }', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('data-canonical-operation={SITES_CLOSE_FORM_OPERATION_ID}', $frontend);
        $this->assertStringContainsString('disabled={m.isPending} onClick={closeForm}', $frontend);
        $this->assertStringContainsString('setFormOpen(false);setId(null);setName(\'\');setUrl(\'\');setMsg(null)', $frontend);

        $start = strpos($frontend, 'const closeForm=');
        $end = strpos($frontend, 'const openAddForm=', $start);
        $slice = substr($frontend, $start, $end - $start);

        $this->assertStringNotContainsString('apiRequest', $slice);
        $this->assertStringNotContainsString('fetch(', $slice);
        $this->assertStringNotContainsString('.mutate(', $slice);
        $this->assertStringNotContainsString('POST', $slice);
        $this->assertStringNotContainsString('PATCH', $slice);
        $this->assertStringNotContainsString('DELETE', $slice);
    }

    public function test_control_inherits_exact_active_tenant_and_manage_permission_boundary(): void
    {
        $frontend = (string) file_get_contents(resource_path('js/sites-save-control.tsx'));

        $this->assertStringContainsString('c.api.sites===`/api/tenants/${encodeURIComponent(c.tenant.slug)}/sites`?c.api.sites:null', $frontend);
        $this->assertStringContainsString("context.permissions.includes('sites.manage')", $frontend);
        $this->assertStringContainsString('if(!allowed||!ep)return null;', $frontend);
    }
}
