<?php

namespace Tests\Feature;

use Tests\TestCase;

final class GlobalMediaSourceUrlControlTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-MEDI-3444B17C11';

    public function test_global_media_source_url_operation_is_wired_to_the_authoritative_media_rows(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('media', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/module/media', $operation['route_screen']);
        $this->assertSame('@item.SourceUrl -> @item.SourceUrl', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/GlobalMediaExplorer.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);

        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $control = (string) file_get_contents(resource_path('js/content-explorer-media-source-url-control.tsx'));

        $this->assertStringContainsString("route.key === 'media'", $pages);
        $this->assertStringContainsString(
            '<ContentExplorerMediaSourceUrlControl value={row.source_url} />',
            $pages,
        );
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("parsed.protocol !== 'http:' && parsed.protocol !== 'https:'", $control);
        $this->assertStringContainsString('parsed.username || parsed.password', $control);
        $this->assertStringContainsString('target="_blank"', $control);
        $this->assertStringContainsString('rel="noopener noreferrer"', $control);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
    }

    public function test_existing_media_api_is_the_tenant_scoped_authoritative_source(): void
    {
        $existingGate = (string) file_get_contents(
            base_path('tests/Feature/ContentExplorerMediaSourceUrlControlTerminalityTest.php'),
        );

        $this->assertStringContainsString("authorize('content.view')", (string) file_get_contents(
            app_path('Http/Controllers/ContentApiController.php'),
        ));
        $this->assertStringContainsString('test_permission_and_cross_tenant_media_reads_fail_closed', $existingGate);
        $this->assertStringContainsString('test_media_read_returns_authoritative_persisted_source_url_without_mutation', $existingGate);
        $this->assertStringContainsString('404', $existingGate);
        $this->assertStringContainsString('foreign', strtolower($existingGate));
    }
}
