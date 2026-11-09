<?php

/**
 * Private, single-command cPanel Cron entrypoint.
 *
 * Cron Jobs: <PHP 8.3+ CLI> /private/path/app/aiwm-cpanel-cron.php
 *
 * This is packaged inside app/, never public/. No SSH, daemon or Composer
 * invocation is needed on the hosting account after installation.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(403);
    exit(1);
}

$root = __DIR__;
$artisan = $root.'/artisan';
$autoload = $root.'/vendor/autoload.php';
$lockPath = $root.'/storage/framework/cpanel-cron.lock';

// Fail closed on missing dependencies or symlinked / untrusted lock paths.
if (! is_file($artisan) || ! is_file($autoload) || is_link($lockPath) || ! is_dir(dirname($lockPath))) {
    fwrite(STDERR, "CPANEL_CRON=BLOCKED\n");
    exit(1);
}

$lock = @fopen($lockPath, 'c');
if ($lock === false) {
    fwrite(STDERR, "CPANEL_CRON=LOCK_UNAVAILABLE\n");
    exit(1);
}
@chmod($lockPath, 0600);
if (! flock($lock, LOCK_EX | LOCK_NB)) {
    // Another bounded worker owns the invocation; do not enqueue twice.
    fclose($lock);
    exit(0);
}

try {
    require $autoload;

    $process = new \Symfony\Component\Process\Process([
        PHP_BINARY, '-d', 'memory_limit=192M', $artisan,
        'cpanel:tick', '--no-interaction',
    ], $root);
    $process->setTimeout(110);
    $process->setIdleTimeout(110);
    $process->run();
    if (! $process->isSuccessful()) {
        fwrite(STDERR, "CPANEL_CRON=TICK_FAILED (check private runtime diagnostics)\n");
        exit(1);
    }
    echo "CPANEL_CRON=TICK_COMPLETED\n";
} catch (\Throwable) {
    // No upstream HTTP error, stack, env, keys or absolute private paths in Cron email.
    fwrite(STDERR, "CPANEL_CRON=ERROR (check private runtime diagnostics)\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
