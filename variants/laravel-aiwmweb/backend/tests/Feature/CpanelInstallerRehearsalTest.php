<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CpanelInstallerRehearsalTest extends TestCase
{
    public function test_fresh_mysql_install_locks_wizard_and_preserves_key_on_authenticated_recovery(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Fresh MySQL installer rehearsal runs in the MySQL production-like CI job.');
        }
        $database = 'aimw_rehearsal_'.str_replace('-', '', (string) Str::uuid());
        $directory = sys_get_temp_dir().'/'.$database;
        DB::statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4');
        $connection = config('database.connections.mysql');
        $sessionId = bin2hex(random_bytes(16));
        try {
            mkdir($directory.'/app/bootstrap/cache', 0700, true);
            foreach (['app', 'config', 'database', 'routes', 'resources', 'vendor'] as $name) {
                symlink(base_path($name), $directory.'/app/'.$name);
            }
            foreach (['app.php', 'providers.php'] as $name) {
                copy(base_path('bootstrap/'.$name), $directory.'/app/bootstrap/'.$name);
            }
            foreach (['storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'public/build'] as $path) {
                mkdir($directory.($path === 'public/build' ? '/' : '/app/').$path, 0700, true);
            }
            file_put_contents($directory.'/public/build/manifest.json', '{}');
            file_put_contents($directory.'/app/.cpanel-profile', 'cpanel');
            copy(base_path('../runtime/cpanel/install.php'), $directory.'/public/install.php');
            file_put_contents($directory.'/public/index.php', '<?php');
            $hashes = [];
            foreach (['app/vendor/autoload.php', 'app/bootstrap/app.php', 'public/index.php', 'public/build/manifest.json', 'public/install.php'] as $path) {
                $hashes[$path] = hash_file('sha256', $directory.'/'.$path);
            }
            file_put_contents($directory.'/MANIFEST.json', json_encode(['source_sha' => 'installer-ci-rehearsal', 'files' => $hashes]));
            $script = $directory.'/request.php';
            file_put_contents($script, <<<'CODE'
<?php
$directory = __DIR__;
$input = json_decode(file_get_contents($directory.'/input.json'), true);
session_id($input['session']);
$_SERVER['HTTPS'] = 'on';
$_SERVER['REMOTE_USER'] = 'ci-directory-owner';
$_SERVER['DOCUMENT_ROOT'] = $directory.'/public';
$_SERVER['REQUEST_METHOD'] = $input['method'];
$_POST = $input['post'];
require $directory.'/public/install.php';
CODE);
            $env = [];
            foreach (['APP_ENV', 'APP_KEY', 'APP_URL', 'APP_DEBUG', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'CACHE_STORE', 'SESSION_DRIVER', 'SESSION_CONNECTION', 'QUEUE_CONNECTION'] as $name) {
                $env[$name] = false;
            }
            $run = function (string $method, array $post = []) use ($directory, $script, $sessionId, $env): string {
                file_put_contents($directory.'/input.json', json_encode(['session' => $sessionId, 'method' => $method, 'post' => $post]));
                $process = new Process([PHP_BINARY, $script], $directory, $env);
                $process->setTimeout(60);
                $process->mustRun();

                return $process->getOutput();
            };
            $html = $run('GET');
            preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $match);
            $this->assertNotEmpty($match[1] ?? null);
            $post = ['csrf' => $match[1], 'app_url' => 'https://installer.example', 'db_host' => $connection['host'], 'db_name' => $database, 'db_user' => $connection['username'], 'db_password' => $connection['password'], 'tenant_name' => 'Rehearsal', 'admin_name' => 'Owner', 'admin_email' => 'owner@example.test', 'admin_password' => 'Rehearsal-only-Long-Password!33', 'admin_password_confirmation' => 'Rehearsal-only-Long-Password!33'];
            $result = $run('POST', $post);
            $this->assertFileExists($directory.'/app/storage/installed.lock', strip_tags($result));
            $healthScript = $directory.'/health.php';
            file_put_contents($healthScript, '<?php require __DIR__."/app/vendor/autoload.php"; $app=require __DIR__."/app/bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo json_encode(app(App\\Services\\RuntimeHealthService::class)->ready());');
            $health = new Process([PHP_BINARY, $healthScript], $directory, $env);
            $health->mustRun();
            $healthReport = json_decode($health->getOutput(), true);
            $this->assertSame('ready', $healthReport['status']);
            $this->assertSame('not_required', $healthReport['checks']['redis']['status']);
            $this->assertSame('disabled', $healthReport['checks']['scheduler']['status']);
            $environment = file_get_contents($directory.'/app/.env');
            $this->assertStringContainsString('CPANEL_AI_ENABLED="false"', $environment);
            $this->assertSame(0, fileperms($directory.'/app/.env') & 0077);
            $this->assertSame('', $run('GET'));
            // Simulate interrupted final locking, then authenticate against the
            // existing administrator. No migration reset, seed or key rotation.
            unlink($directory.'/app/storage/installed.lock');
            $recovery = $run('GET');
            preg_match('/name="csrf" value="([a-f0-9]+)"/', $recovery, $recoveryMatch);
            $post['csrf'] = $recoveryMatch[1];
            $run('POST', $post);
            $this->assertFileExists($directory.'/app/storage/installed.lock');
            $this->assertSame($environment, file_get_contents($directory.'/app/.env'));
        } finally {
            File::deleteDirectory($directory);
            DB::statement('DROP DATABASE `'.$database.'`');
        }
    }

    public function test_basic_authorization_header_alone_cannot_claim_the_installer(): void
    {
        $script = base_path('../runtime/cpanel/install.php');
        $code = '$_SERVER["HTTPS"]="on"; $_SERVER["PHP_AUTH_USER"]="unverified"; require '.var_export($script, true).';';
        $process = new Process([PHP_BINARY, '-r', $code], base_path());
        $process->mustRun();
        $this->assertStringContainsString('Directory Privacy', $process->getOutput());
        $this->assertStringNotContainsString('<form', $process->getOutput());
    }
}
