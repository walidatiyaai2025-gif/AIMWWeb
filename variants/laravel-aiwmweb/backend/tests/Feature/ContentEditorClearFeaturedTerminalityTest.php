<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ContentEditorClearFeaturedTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-CONT-A6867078EA';

    public function test_source_and_laravel_bind_exact_clear_featured_semantics(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentEditor.razor'));
        $control = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));

        $this->assertStringContainsString('@onclick="ClearFeatured"', $source);
        $this->assertStringContainsString('private void ClearFeatured()', $source);
        $this->assertStringContainsString('_model.FeaturedMediaId = 0', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('clearContentEditorFeaturedMedia', $control);
        $this->assertStringContainsString('featured_media: 0', $control);
        $this->assertStringContainsString('ContentEditorClearFeaturedControl', $control);
        $this->assertStringContainsString('data-canonical-operation={CONTENT_EDITOR_CLEAR_FEATURED_OPERATION_ID}', $control);
    }

    public function test_clear_featured_is_local_editor_state_only_and_adds_no_remote_mutation(): void
    {
        $control = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));
        $start = strpos($control, 'export function clearContentEditorFeaturedMedia');
        $end = strpos($control, 'export type ContentEditorApprovalResponse', $start);
        $slice = substr($control, $start, $end - $start);

        $this->assertStringNotContainsString('apiRequest', $slice);
        $this->assertStringNotContainsString('fetch(', $slice);
        $this->assertStringNotContainsString('PATCH', $slice);
        $this->assertStringNotContainsString('POST', $slice);
        $this->assertStringNotContainsString('success', strtolower($slice));
    }
}
