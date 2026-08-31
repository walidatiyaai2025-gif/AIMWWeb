<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TemporaryDirectoryTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-PLAT-3025C8E82B';

    public function test_exact_canonical_operation_is_get_temporary_directory(): void
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
        $this->assertSame('service:ApplicationPathService', $operation['route_screen']);
        $this->assertSame('GetTemporaryDirectory', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Infrastructure/Paths/ApplicationPathService.cs',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertFalse((bool) $operation['tenant_owned']);
    }

    public function test_native_temporary_root_is_stable_application_owned_storage(): void
    {
        $temporaryDirectory = storage_path('app/temp');
        $applicationDataRoot = rtrim(storage_path('app'), DIRECTORY_SEPARATOR);
        $storageRoot = rtrim(storage_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $normalized = rtrim($temporaryDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        $this->assertSame($temporaryDirectory, app()->storagePath('app/temp'));
        $this->assertSame($applicationDataRoot.DIRECTORY_SEPARATOR.'temp', $temporaryDirectory);
        $this->assertStringStartsWith($storageRoot, $normalized);
        $this->assertSame('temp', basename($temporaryDirectory));
        $this->assertStringNotContainsString('tenant', strtolower($temporaryDirectory));
        $this->assertStringNotContainsString('user', strtolower($temporaryDirectory));
        $this->assertNotSame(rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR), rtrim($temporaryDirectory, DIRECTORY_SEPARATOR));
    }

    public function test_temporary_root_is_not_exposed_by_the_public_storage_link(): void
    {
        $temporaryDirectory = rtrim(storage_path('app/temp'), DIRECTORY_SEPARATOR);
        $publicRoot = rtrim((string) config('filesystems.disks.public.root'), DIRECTORY_SEPARATOR);
        $linkedTargets = array_map(
            static fn ($path): string => rtrim((string) $path, DIRECTORY_SEPARATOR),
            array_values((array) config('filesystems.links', [])),
        );

        $this->assertNotSame($publicRoot, $temporaryDirectory);
        $this->assertNotContains($temporaryDirectory, $linkedTargets, true);
    }

    public function test_runtime_ensures_uses_and_cleans_the_application_temporary_directory(): void
    {
        $temporaryDirectory = storage_path('app/temp');
        $probe = $temporaryDirectory.DIRECTORY_SEPARATOR.'aimw-temporary-directory-parity-probe.tmp';

        File::ensureDirectoryExists($temporaryDirectory);

        try {
            $this->assertDirectoryExists($temporaryDirectory);
            $this->assertSame($temporaryDirectory, realpath($temporaryDirectory));

            File::put($probe, self::OPERATION_ID);

            $this->assertFileExists($probe);
            $this->assertSame(self::OPERATION_ID, File::get($probe));
            $this->assertStringStartsWith(
                rtrim($temporaryDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR,
                (string) realpath($probe),
            );
        } finally {
            File::delete($probe);
        }

        $this->assertFileDoesNotExist($probe);
    }
}
