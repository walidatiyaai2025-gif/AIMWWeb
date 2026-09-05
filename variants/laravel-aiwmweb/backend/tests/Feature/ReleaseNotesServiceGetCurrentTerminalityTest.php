<?php

namespace Tests\Feature;

use App\Platform\ReleaseNotesService;
use Tests\TestCase;

class ReleaseNotesServiceGetCurrentTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-PLAT-57B1A0F5E3';

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_exact_canonical_operation_is_release_notes_get_current(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('platform', $operation['domain']);
        $this->assertSame('service', $operation['kind']);
        $this->assertSame('service:ReleaseNotesService', $operation['route_screen']);
        $this->assertSame('GetCurrent', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Services/ReleaseNotesService.cs', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertSame('none', $operation['external_dependency']);
        $this->assertFalse((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_get_current_normalizes_whitespace_v_prefix_and_case_then_returns_matching_release(): void
    {
        $path = $this->temporaryFile(<<<'MD'
## v2.3.4 - 2026-09-01 - Current release
- Current change

## vPreview-Channel
- Preview change
MD);
        $service = new ReleaseNotesService($path);

        $release = $service->getCurrent('  vVpreview-channel  ');

        $this->assertNotNull($release);
        $this->assertSame('Preview-Channel', $release['version']);
        $this->assertSame('Version Preview-Channel', $release['title']);
        $this->assertSame(['Preview change'], $release['changes']);
    }

    public function test_get_current_returns_first_exact_version_match_from_get_all(): void
    {
        $path = $this->temporaryFile(<<<'MD'
## 1.2.3 - 2026-09-01 - First match
- First

## v1.2.3 - 2026-09-02 - Later duplicate
- Later
MD);
        $service = new ReleaseNotesService($path);

        $release = $service->getCurrent(' V1.2.3 ');

        $this->assertNotNull($release);
        $this->assertSame('First match', $release['title']);
        $this->assertSame(['First'], $release['changes']);
    }

    public function test_get_current_returns_null_when_version_is_absent_without_fabricating_release_data(): void
    {
        $path = $this->temporaryFile("## 1.0.0 - 2026-09-01 - Existing\n- Existing change\n");
        $service = new ReleaseNotesService($path);

        $this->assertNull($service->getCurrent('9.9.9'));
        $this->assertNull((new ReleaseNotesService($path.'.missing'))->getCurrent('1.0.0'));
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'aimw-release-current-');
        $this->assertNotFalse($path);
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
