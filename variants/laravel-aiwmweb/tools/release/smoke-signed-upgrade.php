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
$config->set('system_updates.public_root', $root.'/public');

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

    // First exercise the FULL byte-for-byte Update ZIP from the release
    // packager against the real previously installed Setup ZIP. Its signing
    // key is ephemeral and never used for production releases.
    if (isset($argv[2], $argv[3])) {
        $candidatePath = realpath($argv[2]);
        $publicKeyFile = realpath($argv[3]);
        $assert($candidatePath !== false && $publicKeyFile !== false, 'Packaged signed update artifacts missing');
        $trustedPublicKey = trim((string) file_get_contents($publicKeyFile));
        $assert(strlen((string) base64_decode($trustedPublicKey, true)) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'Invalid CI test public key');

        $config->set('system_updates.public_key', $trustedPublicKey);
        $fullUpdater = $app->make(SystemUpdateService::class);
        $fullUpload = new UploadedFile($candidatePath, 'AIWMWeb-Laravel-signed-bundle.zip', 'application/zip', null, true);
        $fullPreview = $fullUpdater->verifyUpload($fullUpload);
        $assert($fullPreview['file_count'] > 100, 'Full signed release must include real Laravel runtime files');
        $fullReceipt = $fullUpdater->install(1, $fullUpload);
        $assert($fullReceipt['version'] === $fullPreview['version'], 'Full package version was not installed');
        $assert(isset($fullReceipt['database_backup']['id']) && $fullReceipt['database_backup']['verified'], 'Full package lacked verified MySQL backup');
        $assert((int) DB::table('users')->count() === (int) $originalUsers, 'Full signed package lost users');
        $assert(DB::table('tenants')->where('slug', $slug)->exists(), 'Full signed package lost tenant');
        $assert(hash_file('sha256', $envPath) === $originalEnvHash, 'Full signed package changed .env');
        $assert(hash_equals($uploadBytes, file_get_contents($protectedUpload)), 'Full signed package changed uploads');
        $assert(! is_file(storage_path('framework/down')), 'Full package left maintenance enabled');

        // Prove the encrypted pre-upgrade snapshot can actually reconstruct the
        // users and tenant data in a SEPARATE EMPTY MySQL schema. This never
        // rewrites production or the installed test site's live database.
        if (isset($argv[4]) && $argv[4] !== '') {
            $mysql = config('database.connections.mysql');
            $host = (string) ($mysql['host'] ?? '127.0.0.1');
            $port = (string) ($mysql['port'] ?? '3306');
            $dsn = 'mysql:host='.$host.';port='.$port.';charset=utf8mb4';
            $adminPdo = new \PDO($dsn, 'root', $argv[4], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $recoverySchema = 'aiwm_ci_recovery_'.bin2hex(random_bytes(6));
            $adminPdo->exec('CREATE DATABASE `'.$recoverySchema.'` CHARACTER SET utf8mb4');
            try {
                $recovered = new \PDO(
                    'mysql:host='.$host.';port='.$port.';dbname='.$recoverySchema.';charset=utf8mb4',
                    'root',
                    $argv[4],
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
                );
                $snapshot = $app->make(VerifiedMysqlBackupService::class);
                $snapshot->restoreToEmptyDatabase($fullReceipt['database_backup']['id'], $recovered);
                $assert((int) $recovered->query('SELECT COUNT(*) FROM users')->fetchColumn() === (int) $originalUsers, 'Recovered user count differs');
                $assert(
                    (int) $recovered->query('SELECT COUNT(*) FROM tenants WHERE slug='.$recovered->quote($slug))->fetchColumn() === 1,
                    'Recovery lost original tenant',
                );
                $restoredValue = $recovered->query(
                    'SELECT value FROM scoped_settings WHERE tenant_id='.(int) $tenantId.
                    ' AND `key`='.$recovered->quote('ci.upgrade.preservation'),
                )->fetchColumn();
                $assert(json_decode((string) $restoredValue, true) === ['preserved' => true], 'Recovery lost tenant settings');
                try {
                    $snapshot->restoreToEmptyDatabase($fullReceipt['database_backup']['id'], $recovered);
                    throw new RuntimeException('RECOVERY ACCEPTED A NONEMPTY TARGET');
                } catch (RuntimeException $e) {
                    $assert(str_contains($e->getMessage(), 'empty isolated MySQL'), 'Nonempty recovery rejection');
                }
                echo "PASS: authenticated database recovery into isolated empty MySQL schema preserves users and tenant data.\n";
            } finally {
                $adminPdo->exec('DROP DATABASE IF EXISTS `'.$recoverySchema.'`');
            }
        }
        echo "PASS: exact release-packager ZIP installed through production updater, with protected data intact.\n";
        $config->set('system_updates.public_key', base64_encode(sodium_crypto_sign_publickey($keys)));
    }

    $migrationName = 'database/migrations/2099_01_01_000000_create_aiwm_ci_signed_upgrade_probe.php';
    $probeMigrationPath = base_path($migrationName);
    $migrationCode = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aiwm_ci_signed_upgrade_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('test_marker')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aiwm_ci_signed_upgrade_probe');
    }
};
PHP;
    $marker = "<?php\n// Exact extracted ZIP signed-upgrade smoke probe.\n";
    $inner = new ZipArchive();
    $assert($inner->open($payload, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Cannot create payload ZIP');
    $assert($inner->addFromString('app/SignedUpgradeAcceptanceProbe.php', $marker), 'Cannot add signed payload');
    $assert($inner->addFromString($migrationName, $migrationCode), 'Cannot add signed migration');
    $assert($inner->close(), 'Cannot close payload ZIP');
    $manifest = json_encode([
        'product' => 'LARAVEL_AIWMWEB',
        'version' => '99.99.99',
        'minimum_supported_version' => '0.0.0',
        'migration_policy' => 'forward_only',
        'schema_migrations' => [$migrationName],
        'sha' => str_repeat('a', 40),
        'package' => 'AIWMWeb-Laravel-update.zip',
        'sha256' => hash_file('sha256', $payload),
        'files' => [
            'app/SignedUpgradeAcceptanceProbe.php' => hash('sha256', $marker),
            $migrationName => hash('sha256', $migrationCode),
        ],
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
    // A trigger would be omitted by a table-only recovery image. Verify
    // that signed updates refuse this schema rather than silently losing it.
    $unsupportedTrigger = 'aiwm_ci_unsupported_backup_trigger';
    DB::statement(
        'CREATE TRIGGER '.$unsupportedTrigger.
        ' BEFORE INSERT ON tenants FOR EACH ROW SET NEW.name = NEW.name',
    );
    try {
        try {
            $service->install(1, $upload);
            throw new RuntimeException('UNSAFE UPDATE ACCEPTED WITH UNSUPPORTED DATABASE TRIGGER');
        } catch (RuntimeException $e) {
            $assert(str_contains($e->getMessage(), 'unsupported schema objects'), 'Unsupported MySQL trigger rejection');
        }
        $assert(! is_file($probePath), 'Rejected trigger schema modified code files');
    } finally {
        DB::statement('DROP TRIGGER IF EXISTS '.$unsupportedTrigger);
    }
    $assert($preview['file_count'] === 2 && $preview['version'] === '99.99.99', 'Signed bundle verification');
    $assert(! is_file($probePath), 'Dry-run verification mutated application');

    $result = $service->install(1, $upload);
    $assert($result['version'] === '99.99.99', 'Incorrect installed release version');
    $assert(\Illuminate\Support\Facades\Schema::hasTable('aiwm_ci_signed_upgrade_probe'), 'Signed migration was not applied');
    $assert(in_array($migrationName, $result['schema_migrations'], true), 'Signed migration not recorded in update receipt');
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
    if (isset($probeMigrationPath) && is_file($probeMigrationPath)) {
        unlink($probeMigrationPath);
    }
    if (isset($protectedUpload) && is_file($protectedUpload)) {
        unlink($protectedUpload);
    }
    $deleteWork($work);
}
