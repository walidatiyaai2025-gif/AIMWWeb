<?php

/**
 * Noninteractive SSH deployment entry point (not a public web route).
 *
 * A GitHub Actions runner transfers this script and a production Ed25519-signed
 * three-part update bundle into Laravel's private system-updates/incoming
 * directory. This script delegates all validation, verified MySQL backup,
 * maintenance mode, migrations, rollback/recovery and version recording to
 * the existing platform updater. No direct ZIP extraction into the live app.
 *
 * php remote-apply-signed-update.php APP_DIR BUNDLE_PATH EXPECTED_SHA EXPECTED_VERSION
 */
declare(strict_types=1);

use App\Platform\SystemUpdateService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;

if (PHP_SAPI !== 'cli' || $argc !== 5) {
    fwrite(STDERR, "BLOCKED: CLI and exactly four arguments required.\n");
    exit(2);
}

[$script, $appArg, $bundleArg, $expectedSha, $expectedVersion] = $argv;
if (preg_match('/^[a-f0-9]{40}$/D', $expectedSha) !== 1 ||
    preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $expectedVersion) !== 1) {
    fwrite(STDERR, "BLOCKED: exact Git commit and SemVer required.\n");
    exit(2);
}

$appRoot = realpath($appArg);
$bundlePath = realpath($bundleArg);
if (! is_string($appRoot) || ! is_file($appRoot.'/bootstrap/app.php') ||
    ! is_file($appRoot.'/vendor/autoload.php') || ! is_file($appRoot.'/.env')) {
    fwrite(STDERR, "BLOCKED: installed Laravel app and .env are required.\n");
    exit(2);
}

// Incoming bundle must remain outside the document root, in app/storage,
// and must be a normal file, not an unsafe symlink.
$incoming = realpath($appRoot.'/storage/app/system-updates/incoming');
if ($incoming === false || ! is_string($bundlePath) ||
    dirname($bundlePath) !== $incoming || is_link($bundleArg) ||
    ! is_file($bundlePath) || ! is_readable($bundlePath) ||
    filesize($bundlePath) < 100 || filesize($bundlePath) > 167772160) {
    fwrite(STDERR, "BLOCKED: update bundle is not in protected private staging.\n");
    exit(2);
}

try {
    require $appRoot.'/vendor/autoload.php';
    $app = require $appRoot.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if (base_path() !== $appRoot) {
        throw new RuntimeException('Incorrect Laravel application bootstrap path.');
    }
    if (! (bool) config('system_updates.enabled') ||
        ! is_string(config('system_updates.public_key')) ||
        config('system_updates.public_key') === '') {
        throw new RuntimeException('Production signed updater is not initialized.');
    }
    $service = app(SystemUpdateService::class);
    $before = $service->state();
    // An earlier run might have installed successfully but failed only during
    // GitHub Release publication. Re-check the durable private receipt and
    // permit exact-identity idempotent retries, never an older/different SHA.
    if (($before['version'] ?? null) === $expectedVersion &&
        ($before['sha'] ?? null) === $expectedSha) {
        echo json_encode([
            'status' => 'ALREADY_DEPLOYED',
            'version' => $expectedVersion,
            'sha' => $expectedSha,
            'installed_at' => $before['installed_at'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
        exit(0);
    }
    $bundle = new UploadedFile($bundlePath, basename($bundlePath), 'application/zip', null, true);

    // Dry-run signed manifest and exact file-by-file hash verification before
    // giving the service permission to make any production modifications.
    $verified = $service->verifyUpload($bundle);
    if (! hash_equals($expectedSha, (string) ($verified['sha'] ?? '')) ||
        ! hash_equals($expectedVersion, (string) ($verified['version'] ?? ''))) {
        throw new RuntimeException('Signed update does not match the approved GitHub source and version.');
    }
    if (version_compare($expectedVersion, (string) $before['version'], '<=')) {
        throw new RuntimeException('Refusing to redeploy the current or an older version.');
    }

    // Actor ID 0 explicitly means an unattended, server-authorized deployment
    // (not an impersonated human/platform administrator).
    $service->install(0, $bundle);
    $after = $service->state();
    if (! hash_equals($expectedSha, (string) ($after['sha'] ?? '')) ||
        ! hash_equals($expectedVersion, (string) ($after['version'] ?? ''))) {
        throw new RuntimeException('Post-deployment installed release receipt mismatch.');
    }
    echo json_encode([
        'status' => 'DEPLOYED',
        'version' => $after['version'],
        'sha' => $after['sha'],
        'previous_version' => $before['version'],
        'installed_at' => $after['installed_at'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $exception) {
    // No secrets, user data, absolute filesystem paths or DB credentials in CI logs.
    fwrite(STDERR, "BLOCKED: signed deployment failed; review private system-update history and recovery state on cPanel.\n");
    exit(1);
}
