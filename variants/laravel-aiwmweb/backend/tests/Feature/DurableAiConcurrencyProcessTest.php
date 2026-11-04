<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DurableAiConcurrencyProcessTest extends TestCase
{
    public function test_independent_processes_share_one_database_admission_gate(): void
    {
        $directory = sys_get_temp_dir().'/aimw-ai-'.Str::uuid();
        mkdir($directory, 0700);
        $database = $directory.'/database.sqlite';
        $counter = $directory.'/counter.json';
        touch($database);
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('t', 32)), 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'CACHE_STORE' => 'array', 'LOG_CHANNEL' => 'null', 'AI_TEST_COUNTER' => $counter];
        $fixture = base_path('tests/fixtures/durable-ai-process.php');
        $first = null;
        try {
            $setup = new Process([PHP_BINARY, $fixture, 'setup'], base_path(), $env);
            $setup->mustRun();
            $first = new Process([PHP_BINARY, $fixture, 'run'], base_path(), $env);
            $first->start();
            $deadline = microtime(true) + 10;
            do {
                $state = is_file($counter) ? json_decode(file_get_contents($counter), true) : [];
                if (($state['active'] ?? 0) === 1) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(1, $state['active'] ?? 0);
            $second = new Process([PHP_BINARY, $fixture, 'run'], base_path(), $env);
            $second->mustRun();
            $this->assertSame('busy', trim($second->getOutput()));
            $first->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $this->assertSame('completed', trim($first->getOutput()));
            $state = json_decode(file_get_contents($counter), true);
            $this->assertSame(1, $state['max']);
            $this->assertSame(1, $state['calls']);
            $this->assertSame(0, $state['active']);
        } finally {
            if ($first?->isRunning()) {
                $first->stop();
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_double_cron_has_one_shared_nonblocking_process_lock(): void
    {
        $directory = sys_get_temp_dir().'/aimw-cron-'.Str::uuid();
        mkdir($directory.'/storage/framework', 0700, true);
        $fake = $directory.'/php';
        $counter = $directory.'/entered';
        file_put_contents($fake, "#!/bin/sh\necho entered >> \"\$AI_TEST_COUNTER\"\nsleep 1\n");
        chmod($fake, 0700);
        $env = ['AIMW_APP_ROOT' => $directory, 'AIMW_PHP_BIN' => $fake, 'AI_TEST_COUNTER' => $counter];
        $script = base_path('../runtime/cpanel/cron.sh');
        $first = new Process(['bash', $script], base_path(), $env);
        try {
            $first->start();
            $deadline = microtime(true) + 5;
            while (! is_file($counter) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $second = new Process(['bash', $script], base_path(), $env);
            $second->mustRun();
            $first->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $this->assertSame(['entered'], file($counter, FILE_IGNORE_NEW_LINES));
        } finally {
            if ($first->isRunning()) {
                $first->stop();
            }
            @unlink($counter);
            unlink($fake);
            @unlink($directory.'/storage/framework/cpanel-cron.lock');
            rmdir($directory.'/storage/framework');
            rmdir($directory.'/storage');
            rmdir($directory);
        }
    }
}
