<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ApplicationDataDirectoryTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-PLAT-A2624EDC66';

    public function test_exact_canonical_operation_is_get_application_data_directory(): void
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
        $this->assertSame('GetApplicationDataDirectory', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Infrastructure/Paths/ApplicationPathService.cs',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
    }

    public function test_laravel_native_filesystem_contract_uses_the_application_data_root(): void
    {
        $applicationDataDirectory = storage_path('app');
        File::ensureDirectoryExists($applicationDataDirectory);

        $this->assertDirectoryExists($applicationDataDirectory);
        $this->assertSame($applicationDataDirectory, app()->storagePath('app'));

        $localRoot = rtrim((string) config('filesystems.disks.local.root'), DIRECTORY_SEPARATOR);
        $publicRoot = rtrim((string) config('filesystems.disks.public.root'), DIRECTORY_SEPARATOR);
        $prefix = rtrim($applicationDataDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        $this->assertStringStartsWith($prefix, $localRoot.DIRECTORY_SEPARATOR);
        $this->assertStringStartsWith($prefix, $publicRoot.DIRECTORY_SEPARATOR);
        $this->assertSame('private', basename($localRoot));
        $this->assertSame('public', basename($publicRoot));
    }

    public function test_application_data_directory_is_application_owned_and_not_caller_controlled(): void
    {
        $applicationDataDirectory = storage_path('app');
        $storageRoot = rtrim(storage_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $normalized = rtrim($applicationDataDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        $this->assertStringStartsWith($storageRoot, $normalized);
        $this->assertSame('app', basename($applicationDataDirectory));
        $this->assertStringNotContainsString('tenant', strtolower($applicationDataDirectory));
        $this->assertStringNotContainsString('user', strtolower($applicationDataDirectory));
    }

    public function test_runtime_can_create_read_and_remove_a_probe_inside_application_data_directory(): void
    {
        $applicationDataDirectory = storage_path('app');
        File::ensureDirectoryExists($applicationDataDirectory);
        $probe = $applicationDataDirectory.DIRECTORY_SEPARATOR.'aimw-application-data-directory-parity-probe.tmp';

        try {
            File::put($probe, self::OPERATION_ID);

            $this->assertFileExists($probe);
            $this->assertSame(self::OPERATION_ID, File::get($probe));
        } finally {
            File::delete($probe);
        }

        $this->assertFileDoesNotExist($probe);
    }
}
