<?php

namespace Tests\Feature;

use Tests\TestCase;

final class ContentEditorSitesLinkTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-CONT-B6BB292F85';

    public function test_source_and_laravel_bind_exact_sites_breadcrumb_contract(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentEditor.razor'));
        $control = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));

        $this->assertStringContainsString('<a href="/sites">@L["Sites"]</a>', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('ContentEditorSitesLinkControl', $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/sites')", $control);
        $this->assertStringContainsString('data-canonical-operation={CONTENT_EDITOR_SITES_LINK_OPERATION_ID}', $control);
    }

    public function test_sites_breadcrumb_is_navigation_only_and_adds_no_mutation_or_identity_override(): void
    {
        $control = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));
        $start = strpos($control, 'export function ContentEditorSitesLinkControl');
        $end = strpos($control, 'export type ContentEditorSnapshot', $start);
        $slice = substr($control, $start, $end - $start);

        $this->assertStringNotContainsString('apiRequest', $slice);
        $this->assertStringNotContainsString('fetch(', $slice);
        $this->assertStringNotContainsString('POST', $slice);
        $this->assertStringNotContainsString('PATCH', $slice);
        $this->assertStringNotContainsString('DELETE', $slice);
        $this->assertStringNotContainsString('tenant_id', $slice);
        $this->assertStringNotContainsString('site_id', $slice);
    }
}
