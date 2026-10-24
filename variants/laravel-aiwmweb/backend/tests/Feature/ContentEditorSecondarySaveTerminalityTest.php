<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ContentEditorSecondarySaveTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-CONT-289CEEF3BA';

    private const EXISTING_SAVE_OPERATION_ID = 'AIMW-BILL-42F7590F00';

    public function test_exact_canonical_operation_is_content_editor_button_control_14(): void
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
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame(
            '/sites/{SiteId:guid}/content/{ContentType}/{WordPressId:int}/edit',
            $operation['route_screen'],
        );
        $this->assertSame('button control 14', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/ContentEditor.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_source_button_14_is_the_secondary_submit_bound_to_existing_save_async(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentEditor.razor'),
        );

        $this->assertStringContainsString(
            '<EditForm Model="_model" OnValidSubmit="SaveAsync">',
            $source,
        );
        $this->assertStringContainsString(
            '<button class="btn primary full" type="submit" disabled="@(_saving || _submittingApproval)">@(_saving ? L["Saving"] : L["SaveToWordPress"])</button>',
            $source,
        );
    }

    public function test_secondary_submit_inherits_the_existing_foreign_tenant_404_security_contract(): void
    {
        $existingSaveTest = (string) file_get_contents(
            base_path('tests/Feature/ContentEditorSaveTerminalityTest.php'),
        );

        $this->assertStringContainsString(
            'test_guest_missing_permission_foreign_tenant_and_foreign_site_fail_closed',
            $existingSaveTest,
        );
        $this->assertStringContainsString('->assertNotFound()', $existingSaveTest);
        $this->assertStringContainsString('/api/v1/tenants/beta/sites/{$betaSite->id}/content/post/202/editor', $existingSaveTest);
        $this->assertStringContainsString('content.edit', $existingSaveTest);
    }

    public function test_laravel_mounts_a_second_submit_control_without_creating_a_second_mutation(): void
    {
        $secondary = (string) file_get_contents(
            resource_path('js/content-editor-secondary-save-control.tsx'),
        );
        $editor = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));

        $this->assertStringContainsString(
            "CONTENT_EDITOR_SECONDARY_SAVE_OPERATION_ID = '".self::OPERATION_ID."'",
            $secondary,
        );
        $this->assertStringContainsString('type="submit"', $secondary);
        $this->assertStringContainsString(
            'data-canonical-operation={CONTENT_EDITOR_SECONDARY_SAVE_OPERATION_ID}',
            $secondary,
        );
        $this->assertStringNotContainsString('onClick=', $secondary);
        $this->assertStringNotContainsString('apiRequest(', $secondary);
        $this->assertStringNotContainsString('tenantUrl(', $secondary);

        $this->assertStringContainsString('ContentEditorSecondarySaveControl', $editor);
        $this->assertStringContainsString('onSubmit={save}', $editor);
        $this->assertStringContainsString(
            "export const CONTENT_EDITOR_SAVE_OPERATION_ID = '".self::EXISTING_SAVE_OPERATION_ID."'",
            $editor,
        );
        $this->assertStringContainsString("method: 'PATCH'", $editor);
        $this->assertStringContainsString('const authoritative = await reload()', $editor);
        $this->assertSame(1, substr_count($secondary, self::OPERATION_ID));
    }
}
