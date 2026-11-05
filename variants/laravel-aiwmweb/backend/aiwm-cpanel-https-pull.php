<?php

/**
 * AIWMWeb Laravel cPanel HTTPS release puller.
 *
 * PRIVATE CLI ONLY: install into app/ alongside artisan, never public/.
 * cPanel Cron: /path/to/php /home/walidatiya/public_html/aiwm.walidatiya.com/app/aiwm-cpanel-https-pull.php
 * The platform itself verifies GitHub Release Ed25519 signatures, bundled
 * SHA-256 file digests, minimum versions, and verified MySQL backups.
 */
declare(strict_types=1);

use App\Platform\SystemUpdateService;
use App\Services\RuntimeHealthService;
use Illuminate\Contracts\Console\Kernel;

if (PHP_SAPI !== 'cli' || isset($_SERVER['REQUEST_METHOD']) || $argc !== 1) {
    exit(2);
}

$root = realpath(__DIR__);
if ($root === false || ! is_file($root.'/.env') ||
    ! is_file($root.'/artisan') || ! is_file($root.'/vendor/autoload.php') ||
    ! is_file($root.'/bootstrap/app.php') ||
    realpath(dirname($root).'/public') === false ||
    ! extension_loaded('sodium') || ! extension_loaded('zip') ||
    ! extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "BLOCKED: installed private Laravel runtime or PHP extensions unavailable.\n");
    exit(2);
}

try {
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    // A misplaced cron script, untrusted domain, incomplete setup or changed
    // deployment root must never trigger an automatic production mutation.
    $publicRoot = realpath((string) config('system_updates.public_root', ''));
    $domainRoot = realpath(dirname($root).'/public');
    if (base_path() !== $root ||
        parse_url((string) config('app.url'), PHP_URL_HOST) !== 'aiwm.walidatiya.com' ||
        (bool) config('app.debug') ||
        ! $app->environment('production') ||
        ! (bool) config('system_updates.enabled') ||
        ! (bool) config('system_updates.auto_pull_enabled') ||
        ! is_string($publicRoot) || $publicRoot !== $domainRoot ||
        ! is_file($root.'/storage/app/aiwm-install/installed.lock')) {
        throw new RuntimeException('Unapproved hosting environment or automatic update configuration.');
    }

    $private = storage_path('app/system-updates');
    if (! is_dir($private) && ! mkdir($private, 0700, true) && ! is_dir($private)) {
        throw new RuntimeException('Cannot initialize protected Cron lock directory.');
    }
    if (is_link($private) || ! is_writable($private)) {
        throw new RuntimeException('Cron update lock directory is unsafe.');
    }
    $lockPath = $private.'/cron-pull.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('Cron update lock symlink rejected.');
    }
    $lock = @fopen($lockPath, 'c');
    if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
        echo "SKIPPED: another release check or installation is running.\n";
        exit(0);
    }

    try {
        $updates = $app->make(SystemUpdateService::class);
        $candidate = $updates->latest();
        $previous = $updates->state();
        if (! ($candidate['available'] ?? false)) {
            echo 'UP_TO_DATE '.(string) $previous['version']."\n";
            exit(0);
        }

        // There is no unsanctioned second update mechanism: the production
        // service fetches and re-verifies the signed manifest and ZIP itself,
        // owns maintenance/backup/migrations and writes the installed receipt.
        $release = $updates->install(0);
        if (($release['sha'] ?? '') !== ($candidate['sha'] ?? '') ||
            ($release['version'] ?? '') !== ($candidate['version'] ?? '')) {
            throw new RuntimeException('Signed Release changed during promotion; inspect private history.');
        }
        $installed = $updates->state();
        if (($installed['sha'] ?? '') !== ($release['sha'] ?? '') ||
            ($installed['version'] ?? '') !== ($release['version'] ?? '')) {
            throw new RuntimeException('Installed receipt does not match signed Release.');
        }

        $health = $app->make(RuntimeHealthService::class)->ready();
        if (($health['status'] ?? null) !== 'ready') {
            throw new RuntimeException('Live runtime readiness failed after promotion.');
        }
        echo 'DEPLOYED '.(string) $installed['version'].' '.(string) $installed['sha']."\n";
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
} catch (Throwable $exception) {
    // No secret, DB connection string, token, absolute file path or user data
    // is ever printed to the cPanel Cron email/output stream.
    fwrite(STDERR, "BLOCKED: signed HTTPS update check/install failed. Consult private Laravel update history and health checks.\n");
    exit(1);
}
