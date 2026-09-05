<?php

namespace Tests\Feature;

use App\Logging\LogReaderService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LogReaderGetFilesTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-OPER-85A7A01127';

    private string $originalStoragePath;

    private string $testStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = $this->app->storagePath();
        $this->testStoragePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'aimw-log-reader-'.bin2hex(random_bytes(6));
        $this->app->useStoragePath($this->testStoragePath);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStoragePath);
        File::deleteDirectory($this->testStoragePath);

        parent::tearDown();
    }

    public function test_exact_canonical_operation_is_log_reader_get_files(): void
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
        $this->assertSame('service:LogReaderService', $operation['route_screen']);
        $this->assertSame('GetFiles', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Services/LogReaderService.cs',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
    }

    public function test_missing_logs_directory_returns_an_empty_inventory(): void
    {
        $this->assertDirectoryDoesNotExist(storage_path('logs'));

        $service = $this->app->make(LogReaderService::class);

        $this->assertSame([], $service->getFiles());
    }

    public function test_get_files_returns_only_top_level_log_and_text_metadata_newest_first(): void
    {
        $logsDirectory = storage_path('logs');
        $nestedDirectory = $logsDirectory.DIRECTORY_SEPARATOR.'nested';
        File::ensureDirectoryExists($nestedDirectory);

        $oldPath = $logsDirectory.DIRECTORY_SEPARATOR.'old.log';
        $newPath = $logsDirectory.DIRECTORY_SEPARATOR.'newest.TXT';
        $ignoredPath = $logsDirectory.DIRECTORY_SEPARATOR.'ignore.json';
        $nestedPath = $nestedDirectory.DIRECTORY_SEPARATOR.'nested.log';

        File::put($oldPath, 'old');
        File::put($newPath, 'newest-entry');
        File::put($ignoredPath, 'ignored');
        File::put($nestedPath, 'nested');

        $oldTimestamp = 1_700_000_000;
        $newTimestamp = 1_800_000_000;
        touch($oldPath, $oldTimestamp);
        touch($newPath, $newTimestamp);
        clearstatcache(true);

        $files = $this->app->make(LogReaderService::class)->getFiles();

        $this->assertCount(2, $files);
        $this->assertSame(['newest.TXT', 'old.log'], array_column($files, 'name'));
        $this->assertSame($newPath, $files[0]['path']);
        $this->assertSame(strlen('newest-entry'), $files[0]['size']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $newTimestamp), $files[0]['last_write_utc']);
        $this->assertSame($oldPath, $files[1]['path']);
        $this->assertSame(strlen('old'), $files[1]['size']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $oldTimestamp), $files[1]['last_write_utc']);
        $this->assertNotContains('ignore.json', array_column($files, 'name'));
        $this->assertNotContains('nested.log', array_column($files, 'name'));
    }

    public function test_inventory_root_is_application_owned_and_has_no_caller_selected_path(): void
    {
        $service = $this->app->make(LogReaderService::class);
        $constructor = (new \ReflectionClass($service))->getConstructor();

        $this->assertNull($constructor);
        $this->assertStringEndsWith(DIRECTORY_SEPARATOR.'logs', storage_path('logs'));
    }
}
