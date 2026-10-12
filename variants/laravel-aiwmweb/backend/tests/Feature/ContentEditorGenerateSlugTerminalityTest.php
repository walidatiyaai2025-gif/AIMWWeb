<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ContentEditorGenerateSlugTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-CONT-564636DDC9';

    public function test_source_and_laravel_bind_exact_generate_slug_semantics(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentEditor.razor'));
        $control = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));

        $this->assertStringContainsString('@onclick="GenerateSlug"', $source);
        $this->assertStringContainsString('private void GenerateSlug()', $source);
        $this->assertStringContainsString('PlainText(_model.Title).Trim().ToLowerInvariant()', $source);
        $this->assertStringContainsString('Regex.Replace(plain, @"[^\p{L}\p{N}]+", "-").Trim', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('generateContentEditorSlug', $control);
        $this->assertStringContainsString("replace(/[^\\p{L}\\p{N}]+/gu, '-')", $control);
        $this->assertStringContainsString('ContentEditorGenerateSlugControl', $control);
        $this->assertStringContainsString('data-canonical-operation={CONTENT_EDITOR_GENERATE_SLUG_OPERATION_ID}', $control);
        $this->assertStringContainsString("onGenerate={(slug) => set('slug', slug)}", $control);
    }

    public function test_generate_slug_is_local_editor_state_only(): void
    {
        $control = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));
        $start = strpos($control, 'export function generateContentEditorSlug');
        $end = strpos($control, 'export function ContentEditorClearFeaturedControl', $start);
        $slice = substr($control, $start, $end - $start);

        $this->assertStringNotContainsString('apiRequest', $slice);
        $this->assertStringNotContainsString('fetch(', $slice);
        $this->assertStringNotContainsString('PATCH', $slice);
        $this->assertStringNotContainsString('POST', $slice);
        $this->assertStringNotContainsString('tenant', strtolower($slice));
        $this->assertStringNotContainsString('success', strtolower($slice));
    }
}
