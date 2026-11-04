<?php

declare(strict_types=1);

/**
 * CI-only acceptance: upgrade the ACTUAL extracted cPanel Setup ZIP installation
 * using an ephemeral Ed25519-signed transport ZIP through the production updater.
 * Never uses production signing keys, secrets or a live production schema.
 */
use App\Platform\SystemUpdateService;
use App\Platform\VerifiedMysqlBackupService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use ZipArchive;

$root = realpath($argv[1] ?? '');
if ($root === false || ! is_file($root.'/app/bootstrap/app.php') ||
    ! is_file($root.'/app/vendor/autoload.php')) {
    fwrite(STDERR, "Expected extracted and installed cPanel Setup ZIP root.\n");
    exit(1);
}

require $root.'/app/vendor/autoload.php';
$app = require $root.'/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'mysql') {
    throw new RuntimeException('Real MySQL is mandatory for the ZIP upgrade acceptance test.');
}
if (! extension_loaded('sodium')) {
    throw new RuntimeException('Sodium is required to sign the ephemeral test release.');
}

$work = sys_get_temp_dir().'/aiwm-signed-upgrade-'.bin2hex(random_bytes(8));
if (! mkdir($work, 0700)) {
    throw new RuntimeException('Cannot create private test work directory.');
}

$probePath = base_path('app/SignedUpgradeAcceptanceProbe.php');
if (is_file($probePath)) {
    throw new RuntimeException('Test probe already exists; refusing to overwrite.');
}
$payload = $work.'/payload.zip';
$transport = $work.'/transport.zip';
$keys = sodium_crypto_sign_keypair();
$config = config();
$config->set('system_updates.enabled', true);
$config->set('system_updates.public_key', base64_encode(sodium_crypto_sign_publickey($keys)));
$config->set('system_updates.current_version', '0.0.0');

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException('Signed upgrade acceptance FAILED: '.$message);
    }
};
$deleteWork = static function (string $directory): void {
    foreach (glob($directory.'/*') ?: [] as $entry) {
        if (is_file($entry)) {
            unlink($entry);
        }
    }
    rmdir($directory);
};

try {
    $slug = 'upgrade-'.bin2hex(random_bytes(6));
    $tenantId = DB::table('tenants')->insertGetId([
        'name' => 'CI Upgrade Data Preservation',
        'slug' => $slug,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('scoped_settings')->insert([
        'tenant_id' => $tenantId,
        'scope' => 'tenant',
        'key' => 'ci.upgrade.preservation',
        'value' => json_encode(['preserved' => true], JSON_THROW_ON_ERROR),
        'is_secret' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $originalUsers = DB::table('users')->count();
    $envPath = base_path('.env');
    $assert(is_file($envPath), 'Installer did not create .env');
    $originalEnvHash = hash_file('sha256', $envPath);

    // Uploads and runtime configuration are never payload targets and must
    // remain byte-identical through a signed application update.
    $uploadDirectory = storage_path('app/public/uploads');
    if (! is_dir($uploadDirectory) && ! mkdir($uploadDirectory, 0750, true)) {
        throw new RuntimeException('Cannot create CI test upload directory');
    }
    $protectedUpload = $uploadDirectory.'/aiwm-upgrade-'.bin2hex(random_bytes(6)).'.bin';
    $uploadBytes = random_bytes(160);
    $assert(file_put_contents($protectedUpload, $uploadBytes) === strlen($uploadBytes), 'Cannot create protected upload');

    $marker = "<?php\n// Exact extracted ZIP signed-upgrade smoke probe.\n";
    $inner = new ZipArchive();
    $assert($inner->open($payload, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Cannot create payload ZIP');
    $assert($inner->addFromString('app/SignedUpgradeAcceptanceProbe.php', $marker), 'Cannot add signed payload');
    $assert($inner->close(), 'Cannot close payload ZIP');
    $manifest = json_encode([
        'product' => 'LARAVEL_AIWMWEB',
        'version' => '99.99.99',
        'sha' => str_repeat('a', 40),
        'package' => 'AIWMWeb-Laravel-update.zip',
        'sha256' => hash_file('sha256', $payload),
        'files' => ['app/SignedUpgradeAcceptanceProbe.php' => hash('sha256', $marker)],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $signature = base64_encode(sodium_crypto_sign_detached(
        $manifest,
        sodium_crypto_sign_secretkey($keys),
    ));
    $bundle = new ZipArchive();
    $assert($bundle->open($transport, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Cannot create transport ZIP');
    $bundle->addFromString('AIWMWeb-Laravel-release.json', $manifest);
    $bundle->addFromString('AIWMWeb-Laravel-release.sig', $signature);
    $bundle->addFile($payload, 'AIWMWeb-Laravel-update.zip');
    $assert($bundle->close(), 'Cannot close transport ZIP');

    $service = $app->make(SystemUpdateService::class);

    // The same real installer must reject a forged detached signature before
    // any code mutation, even when the inner ZIP and SHA-256 are otherwise valid.
    $forgedPath = $work.'/forged.zip';
    $forged = new ZipArchive();
    $assert($forged->open($forgedPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Cannot create forged fixture');
    $forged->addFromString('AIWMWeb-Laravel-release.json', $manifest);
    $forged->addFromString('AIWMWeb-Laravel-release.sig', base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES)));
    $forged->addFile($payload, 'AIWMWeb-Laravel-update.zip');
    $assert($forged->close(), 'Cannot close forged fixture');
    try {
        $service->verifyUpload(new UploadedFile($forgedPath, 'forged.zip', 'application/zip', null, true));
        throw new RuntimeException('FORGED SIGNATURE ACCEPTED');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'signature could not be verified'), 'Invalid-signature rejection');
    }
    $assert(! is_file($probePath), 'Forged signature mutated application');

    $upload = new UploadedFile($transport, 'AIWMWeb-Update-v99.99.99.zip', 'application/zip', null, true);
    $preview = $service->verifyUpload($upload);

    // Failure injection against the REAL MySQL upgrade: a database view cannot
    // be covered by the InnoDB snapshot contract. Reject before any mutation.
    $unsupportedView = 'aiwm_ci_unsupported_backup_view';
    DB::statement('CREATE VIEW '.$unsupportedView.' AS SELECT 1 AS id');
    try {
        try {
            $service->install(1, $upload);
            throw new RuntimeException('UNSAFE UPDATE ACCEPTED WITH UNSUPPORTED DATABASE VIEW');
        } catch (RuntimeException $e) {
            $assert(str_contains($e->getMessage(), 'Non-InnoDB tables or views'), 'Unsupported MySQL schema rejection');
        }
        $assert(! is_file($probePath), 'Rejected schema modified code files');
    } finally {
        DB::statement('DROP VIEW IF EXISTS '.$unsupportedView);
    }
    $assert($preview['file_count'] === 1 && $preview['version'] === '99.99.99', 'Signed bundle verification');
    $assert(! is_file($probePath), 'Dry-run verification mutated application');

    $result = $service->install(1, $upload);
    $assert($result['version'] === '99.99.99', 'Incorrect installed release version');
    $assert(is_file($probePath) && hash_file('sha256', $probePath) === hash('sha256', $marker), 'Signed code payload not applied');
    $assert(isset($result['database_backup']['id']) && $result['database_backup']['verified'] === true, 'Verified MySQL backup receipt missing');
    $dbReceipt = $app->make(VerifiedMysqlBackupService::class)->verify($result['database_backup']['id']);
    $assert($dbReceipt['sha256'] === $result['database_backup']['sha256'], 'Encrypted database backup unreadable');
    $assert((int) DB::table('users')->count() === (int) $originalUsers, 'Existing users changed');
    $assert(hash_file('sha256', $envPath) === $originalEnvHash, 'Live .env changed during update');
    $assert(is_file($protectedUpload) && hash_equals($uploadBytes, file_get_contents($protectedUpload)), 'Uploaded file was changed');
    $assert(DB::table('tenants')->where('slug', $slug)->exists(), 'Tenant deleted during upgrade');
    $value = DB::table('scoped_settings')->where('tenant_id', $tenantId)
        ->where('key', 'ci.upgrade.preservation')->value('value');
    $assert(json_decode((string) $value, true) === ['preserved' => true], 'Tenant settings changed');
    $assert(! is_file(storage_path('framework/down')), 'Site remained in maintenance after successful update');

    // A previously installed signed release is never eligible for reinstallation.
    try {
        $service->verifyUpload($upload);
        throw new RuntimeException('SIGNED REPLAY/DOWNGRADE ACCEPTED');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'Only newer signed releases'), 'Replay/downgrade rejection');
    }

    echo "PASS: signed ZIP verified, applied after authenticated MySQL backup; tenant, settings and user count preserved.\n";
} finally {
    if (is_file($probePath)) {
        unlink($probePath);
    }
    if (isset($protectedUpload) && is_file($protectedUpload)) {
        unlink($protectedUpload);
    }
    $deleteWork($work);
}
