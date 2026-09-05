<?php

namespace Tests\Feature;

use App\Platform\ReleaseNotesService;
use Tests\TestCase;

class ReleaseNotesServiceGetAllTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-PLAT-15C5517022';

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_exact_canonical_operation_is_release_notes_get_all(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('platform', $operation['domain']);
        $this->assertSame('service', $operation['kind']);
        $this->assertSame('service:ReleaseNotesService', $operation['route_screen']);
        $this->assertSame('GetAll', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Services/ReleaseNotesService.cs', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertSame('none', $operation['external_dependency']);
        $this->assertFalse((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_missing_release_notes_file_returns_truthful_empty_collection(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'aimw-release-notes-missing-'.bin2hex(random_bytes(8)).'.md';

        $service = new ReleaseNotesService($path);

        $this->assertSame([], $service->getAll());
        $this->assertFileDoesNotExist($path);
    }

    public function test_get_all_parses_version_date_title_and_change_lines_like_the_source_contract(): void
    {
        $path = $this->temporaryFile(<<<'MD'
# Release Notes

## v2.3.4 - 2026-09-01 - Current release
- First change
* Second change

Ignored paragraph.

## 2.3.3: Previous release
- Previous change

## vpreview-channel
- Preview change

## 1.0.0 - 2026-99-99 - Invalid date remains a valid release
- Date is intentionally invalid
MD);

        $releases = (new ReleaseNotesService($path))->getAll();

        $this->assertCount(4, $releases);
        $this->assertSame([
            'version' => '2.3.4',
            'date' => '2026-09-01',
            'title' => 'Current release',
            'changes' => ['First change', 'Second change'],
        ], $releases[0]);
        $this->assertSame([
            'version' => '2.3.3',
            'date' => null,
            'title' => 'Previous release',
            'changes' => ['Previous change'],
        ], $releases[1]);
        $this->assertSame([
            'version' => 'preview-channel',
            'date' => null,
            'title' => 'Version preview-channel',
            'changes' => ['Preview change'],
        ], $releases[2]);
        $this->assertSame('1.0.0', $releases[3]['version']);
        $this->assertNull($releases[3]['date']);
        $this->assertSame('Invalid date remains a valid release', $releases[3]['title']);
    }

    public function test_get_all_refreshes_cached_release_notes_after_file_mtime_changes(): void
    {
        $path = $this->temporaryFile("## 1.0.0 - 2026-09-01 - First\n- First change\n");
        $service = new ReleaseNotesService($path);

        $first = $service->getAll();
        $second = $service->getAll();

        $this->assertSame($first, $second);
        $this->assertSame('1.0.0', $second[0]['version']);

        file_put_contents($path, "## 1.1.0 - 2026-09-02 - Second\n- Second change\n");
        touch($path, ((int) filemtime($path)) + 2);
        clearstatcache(true, $path);

        $refreshed = $service->getAll();

        $this->assertSame('1.1.0', $refreshed[0]['version']);
        $this->assertSame(['Second change'], $refreshed[0]['changes']);
    }

    public function test_get_all_does_not_return_stale_cache_after_the_file_is_removed(): void
    {
        $path = $this->temporaryFile("## 1.0.0 - 2026-09-01 - First\n- First change\n");
        $service = new ReleaseNotesService($path);

        $this->assertCount(1, $service->getAll());
        unlink($path);

        $this->assertSame([], $service->getAll());
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'aimw-release-notes-');
        $this->assertNotFalse($path);
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
