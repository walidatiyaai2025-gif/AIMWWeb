<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScreenshotsDirectoryTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-PLAT-83994BBD03';

    public function test_exact_canonical_operation_is_get_screenshots_directory(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('platform', $operation['domain']);
        $this->assertSame('service', $operation['kind']);
        $this->assertSame('service:ApplicationPathService', $operation['route_screen']);
        $this->assertSame('GetScreenshotsDirectory', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Infrastructure/Paths/ApplicationPathService.cs',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
    }

    public function test_screenshots_disk_is_private_application_owned_storage(): void
    {
        $root = rtrim((string) config('filesystems.disks.screenshots.root'), DIRECTORY_SEPARATOR);
        $applicationDataRoot = rtrim(storage_path('app'), DIRECTORY_SEPARATOR);

        $this->assertSame('local', config('filesystems.disks.screenshots.driver'));
        $this->assertSame('private', config('filesystems.disks.screenshots.visibility'));
        $this->assertFalse((bool) config('filesystems.disks.screenshots.throw'));
        $this->assertSame($applicationDataRoot.DIRECTORY_SEPARATOR.'screenshots', $root);
        $this->assertStringStartsWith($applicationDataRoot.DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR);
        $this->assertSame('screenshots', basename($root));
        $this->assertStringNotContainsString('tenant', strtolower($root));
        $this->assertStringNotContainsString('user', strtolower($root));
    }

    public function test_screenshots_storage_is_not_exposed_by_the_public_storage_link(): void
    {
        $screenshotsRoot = rtrim((string) config('filesystems.disks.screenshots.root'), DIRECTORY_SEPARATOR);
        $publicRoot = rtrim((string) config('filesystems.disks.public.root'), DIRECTORY_SEPARATOR);
        $linkedTargets = array_map(
            static fn ($path): string => rtrim((string) $path, DIRECTORY_SEPARATOR),
            array_values((array) config('filesystems.links', [])),
        );

        $this->assertNotSame($publicRoot, $screenshotsRoot);
        $this->assertNotContains($screenshotsRoot, $linkedTargets, true);
    }

    public function test_runtime_can_create_read_and_remove_a_probe_on_screenshots_disk(): void
    {
        $disk = Storage::disk('screenshots');
        $probe = 'aimw-screenshots-directory-parity-probe.txt';

        try {
            $this->assertTrue($disk->put($probe, self::OPERATION_ID));
            $this->assertTrue($disk->exists($probe));
            $this->assertSame(self::OPERATION_ID, $disk->get($probe));

            $absolutePath = $disk->path($probe);
            $this->assertFileExists($absolutePath);
            $this->assertStringStartsWith(
                rtrim(storage_path('app/screenshots'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR,
                $absolutePath,
            );
        } finally {
            $disk->delete($probe);
        }

        $this->assertFalse($disk->exists($probe));
    }
}
