<?php

namespace Tests\Feature;

use Tests\TestCase;

class ContentPlannerNewItemTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-BILL-3ABDE4E48F';

    public function test_source_new_item_contract_and_laravel_binding_are_exact(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentPlanner.razor'));
        $control = (string) file_get_contents(resource_path('js/content-planner-new-item-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('@onclick="NewItem"', $source);
        $this->assertStringContainsString('private void NewItem()', $source);
        $this->assertStringContainsString('_editingId = null;', $source);
        $this->assertStringContainsString('_selectedSiteId = string.Empty;', $source);
        $this->assertStringContainsString('_title = _idea = _scheduledLocal = string.Empty;', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('editingId: null', $control);
        $this->assertStringContainsString("selectedSiteId: ''", $control);
        $this->assertStringContainsString("title: ''", $control);
        $this->assertStringContainsString("idea: ''", $control);
        $this->assertStringContainsString("scheduledLocal: ''", $control);
        $this->assertStringContainsString('ContentPlannerNewItemControl context={context}', $app);
    }

    public function test_new_item_control_adds_no_backend_or_provider_mutation(): void
    {
        $control = (string) file_get_contents(resource_path('js/content-planner-new-item-control.tsx'));

        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('useMutation', $control);
        $this->assertStringNotContainsString('method:', $control);
    }
}
