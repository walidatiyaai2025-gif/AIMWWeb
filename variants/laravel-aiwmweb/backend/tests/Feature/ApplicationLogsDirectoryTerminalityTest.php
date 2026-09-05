<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ApplicationLogsDirectoryTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-OPER-55C6982761';

    public function test_exact_canonical_operation_is_get_logs_directory(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('operations', $operation['domain']);
        $this->assertSame('service', $operation['kind']);
        $this->assertSame('service:ApplicationPathService', $operation['route_screen']);
        $this->assertSame('GetLogsDirectory', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Infrastructure/Paths/ApplicationPathService.cs',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
    }

    public function test_laravel_native_logging_contract_uses_one_stable_logs_directory(): void
    {
        $logsDirectory = storage_path('logs');
        File::ensureDirectoryExists($logsDirectory);

        $this->assertDirectoryExists($logsDirectory);
        $this->assertSame($logsDirectory, dirname((string) config('logging.channels.single.path')));
        $this->assertSame($logsDirectory, dirname((string) config('logging.channels.daily.path')));
        $this->assertSame($logsDirectory, dirname((string) config('logging.channels.monthly.path')));
        $this->assertSame($logsDirectory, dirname((string) config('logging.channels.emergency.path')));
    }

    public function test_logs_directory_is_application_owned_and_not_caller_controlled(): void
    {
        $logsDirectory = storage_path('logs');
        $storageRoot = rtrim(storage_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $normalizedLogsDirectory = rtrim($logsDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        $this->assertStringStartsWith($storageRoot, $normalizedLogsDirectory);
        $this->assertSame('logs', basename($logsDirectory));
        $this->assertStringNotContainsString('tenant', strtolower($logsDirectory));
        $this->assertStringNotContainsString('user', strtolower($logsDirectory));
    }

    public function test_runtime_can_write_and_remove_a_probe_inside_the_canonical_logs_directory(): void
    {
        $logsDirectory = storage_path('logs');
        File::ensureDirectoryExists($logsDirectory);
        $probe = $logsDirectory.DIRECTORY_SEPARATOR.'aimw-logs-directory-parity-probe.tmp';

        try {
            File::put($probe, 'AIMW-OPER-55C6982761');

            $this->assertFileExists($probe);
            $this->assertSame(self::OPERATION_ID, File::get($probe));
        } finally {
            File::delete($probe);
        }

        $this->assertFileDoesNotExist($probe);
    }
}
