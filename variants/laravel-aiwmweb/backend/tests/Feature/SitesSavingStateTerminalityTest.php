<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SitesSavingStateTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-CONT-2752ED9351';

    private const EXISTING_SAVE_OPERATION_ID = 'AIMW-BILL-54E4003BE9';

    public function test_exact_source_saving_state_is_adapted_without_duplicating_the_save_mutation(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Sites.razor'),
        );
        $control = (string) file_get_contents(
            resource_path('js/sites-saving-state-control.tsx'),
        );
        $sites = (string) file_get_contents(resource_path('js/sites-save-control.tsx'));

        $this->assertStringContainsString('Text="@(_saving ? L["Saving"] : L["Save"])"', $source);
        $this->assertStringContainsString('Busy="@_saving"', $source);
        $this->assertStringContainsString('Disabled="@_saving"', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('data-canonical-visible-operation={SITES_SAVING_STATE_OPERATION_ID}', $control);
        $this->assertStringContainsString("busy ? 'true' : 'false'", $control);
        $this->assertStringContainsString("'Saving…'", $control);
        $this->assertStringContainsString("'Save'", $control);
        $this->assertStringNotContainsString('apiRequest(', $control);

        $this->assertStringContainsString('SitesSavingStateControl', $sites);
        $this->assertStringContainsString('busy={m.isPending}', $sites);
        $this->assertStringContainsString('saveOperationId={SITES_SAVE_OPERATION_ID}', $sites);
        $this->assertStringContainsString(self::EXISTING_SAVE_OPERATION_ID, $sites);
    }

    public function test_existing_site_save_routes_remain_the_tenant_guarded_mutation_authority(): void
    {
        $create = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites', 'POST'));
        $update = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7', 'PATCH'));

        $this->assertSame(self::EXISTING_SAVE_OPERATION_ID, $create->defaults['canonical_operation_id'] ?? null);
        $this->assertSame(self::EXISTING_SAVE_OPERATION_ID, $update->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $create->gatherMiddleware());
        $this->assertContains('tenant.context', $create->gatherMiddleware());
        $this->assertContains('auth', $update->gatherMiddleware());
        $this->assertContains('tenant.context', $update->gatherMiddleware());
    }
}
