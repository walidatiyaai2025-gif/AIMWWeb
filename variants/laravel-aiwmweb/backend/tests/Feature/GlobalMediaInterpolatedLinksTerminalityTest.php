<?php

namespace Tests\Feature;

use Tests\TestCase;

final class GlobalMediaInterpolatedLinksTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-MEDI-727FAEEB01';

    public function test_exact_global_media_interpolated_operation_is_wired_to_authoritative_rows(): void
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
        $this->assertSame('@($ -> @($', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/GlobalMediaExplorer.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);

        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $control = (string) file_get_contents(resource_path('js/media-interpolated-links-control.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/ContentApiController.php'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('GlobalMediaInterpolatedLinksControl', $pages);
        $this->assertStringContainsString('siteId={row.site_id}', $pages);
        $this->assertStringContainsString('/module/media?site=', $control);
        $this->assertStringContainsString('/explorer?site=', $control);
        $this->assertStringContainsString('normalizeAuthoritativeSiteId', $control);
        $this->assertStringContainsString("authorize('content.view')", $controller);
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
    }

    public function test_source_contains_the_two_interpolated_site_destinations(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/GlobalMediaExplorer.razor'),
        );

        $this->assertStringContainsString('/sites/{item.SiteId}/media', $source);
        $this->assertStringContainsString('/sites/{item.SiteId}/explorer', $source);
    }
}
