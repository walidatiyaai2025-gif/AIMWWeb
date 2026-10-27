<?php

namespace Tests\Feature;

use Tests\TestCase;

final class CommentsRefreshClickedTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-SYNC-D5CE5A213B';

    public function test_source_and_laravel_bind_exact_refresh_clicked_semantics(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/CommentsManager.razor'),
        );
        $frontend = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertGreaterThanOrEqual(2, substr_count($source, 'OnClick="RefreshClickedAsync"'));
        $this->assertStringContainsString(
            'private Task RefreshClickedAsync(MouseEventArgs _) => LoadAsync();',
            $source,
        );

        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('COMMENTS_REFRESH_CLICK_OPERATION_ID', $frontend);
        $this->assertStringContainsString("route.key === 'comments'\n        ? COMMENTS_REFRESH_CLICK_OPERATION_ID", $frontend);
        $this->assertStringContainsString("if (route.key !== 'backups') {\n            await query.refetch();", $frontend);
        $this->assertStringContainsString("route.key === 'comments' || route.key === 'ai-center'", $frontend);
    }
}
