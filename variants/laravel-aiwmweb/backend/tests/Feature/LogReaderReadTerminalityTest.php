<?php

namespace Tests\Feature;

use App\Logging\LogReaderReadService;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class LogReaderReadTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-OPER-FC4C071FAA';

    public function test_exact_canonical_operation_is_log_reader_read(): void
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
        $this->assertSame('Read', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Services/LogReaderService.cs',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
    }

    public function test_service_is_container_resolvable_and_blank_or_missing_allowed_files_are_empty(): void
    {
        File::ensureDirectoryExists(storage_path('logs'));

        $service = app(LogReaderReadService::class);

        $this->assertInstanceOf(LogReaderReadService::class, $service);
        $this->assertSame([], $service->read('   '));
        $this->assertSame([], $service->read(storage_path('logs/aimw-missing-log-reader-read.log')));
    }

    public function test_runtime_tail_is_bounded_clamped_renumbered_and_classified_with_canonical_precedence(): void
    {
        $logsDirectory = storage_path('logs');
        File::ensureDirectoryExists($logsDirectory);
        $path = $logsDirectory.DIRECTORY_SEPARATOR.'aimw-log-reader-read-parity.log';

        $lines = [];
        for ($index = 1; $index <= 75; $index++) {
            $lines[] = "line {$index} information";
        }

        $lines[70] = 'fatal warning error on line 71';
        $lines[71] = 'exception warn on line 72';
        $lines[72] = 'warning trace on line 73';
        $lines[73] = 'trace details on line 74';
        $lines[74] = 'plain line 75';

        try {
            File::put($path, implode(PHP_EOL, $lines).PHP_EOL);

            $service = app(LogReaderReadService::class);
            $result = $service->read($path, 1);

            $this->assertCount(50, $result, 'take below 50 must clamp to 50');
            $this->assertSame(range(1, 50), array_column($result, 'number'));
            $this->assertSame('line 26 information', $result[0]['text']);
            $this->assertSame('plain line 75', $result[49]['text']);
            $this->assertSame('Critical', $result[45]['level']);
            $this->assertSame('Error', $result[46]['level']);
            $this->assertSame('Warning', $result[47]['level']);
            $this->assertSame('Debug', $result[48]['level']);
            $this->assertSame('Information', $result[49]['level']);

            $this->assertCount(75, $service->read($path, 10_000), 'take above 5000 must clamp to 5000');
        } finally {
            File::delete($path);
        }

        $this->assertFileDoesNotExist($path);
    }

    public function test_paths_outside_the_application_owned_logs_root_fail_closed(): void
    {
        $logsDirectory = storage_path('logs');
        File::ensureDirectoryExists($logsDirectory);
        $outside = storage_path('aimw-log-reader-read-outside.log');
        File::put($outside, 'outside');

        try {
            app(LogReaderReadService::class)->read($outside);
            $this->fail('Outside-root log path should have been rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('outside the allowed log directory', $exception->getMessage());
        } finally {
            File::delete($outside);
        }
    }

    public function test_root_sibling_prefix_and_resolved_symlink_escape_are_rejected(): void
    {
        $logsDirectory = storage_path('logs');
        File::ensureDirectoryExists($logsDirectory);
        $siblingDirectory = storage_path('logs-sibling-parity');
        $sibling = $siblingDirectory.DIRECTORY_SEPARATOR.'sibling.log';
        File::ensureDirectoryExists($siblingDirectory);
        File::put($sibling, 'sibling');

        try {
            app(LogReaderReadService::class)->read($sibling);
            $this->fail('Root-sibling prefix path should have been rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        } finally {
            File::deleteDirectory($siblingDirectory);
        }

        if (! function_exists('symlink')) {
            return;
        }

        $outside = storage_path('aimw-log-reader-read-symlink-target.log');
        $link = $logsDirectory.DIRECTORY_SEPARATOR.'aimw-log-reader-read-symlink.log';
        File::put($outside, 'outside target');

        if (! @symlink($outside, $link)) {
            File::delete($outside);

            return;
        }

        try {
            app(LogReaderReadService::class)->read($link);
            $this->fail('Resolved symlink escape should have been rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        } finally {
            @unlink($link);
            File::delete($outside);
        }
    }
}
