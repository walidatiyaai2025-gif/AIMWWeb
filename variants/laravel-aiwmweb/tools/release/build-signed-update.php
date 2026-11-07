<?php

/**
 * Offline release packager. Execute ONLY on a controlled, green build at an
 * immutable SHA. The secret signing key belongs in the release CI secret store,
 * never in Git, the browser, or the cPanel installation.
 *
 * Usage: AIWM_UPDATES_SECRET_KEY=<base64-ed25519-secret> php build-signed-update.php 1.2.3 <40hex-sha> <output-dir>
 */
declare(strict_types=1);

if ($argc !== 4 || ! preg_match('/^\d+\.\d+\.\d+$/D', $argv[1]) ||
    ! preg_match('/^[a-f0-9]{40}$/D', $argv[2])) {
    fwrite(STDERR, "Usage: php build-signed-update.php VERSION COMMIT_SHA OUTPUT_DIR\n");
    exit(2);
}
if (! extension_loaded('sodium') || ! extension_loaded('zip')) {
    fwrite(STDERR, "PHP sodium and zip extensions are required.\n");
    exit(2);
}
$private = base64_decode((string) getenv('AIWM_UPDATES_SECRET_KEY'), true);
if ($private === false || strlen($private) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    fwrite(STDERR, "Missing or invalid signing secret. Never save it in the repository.\n");
    exit(2);
}
$base = realpath(__DIR__.'/../../backend');
if ($base === false || ! is_file($base.'/composer.lock') || ! is_dir($base.'/vendor') ||
    ! is_file($base.'/public/build/manifest.json')) {
    fwrite(STDERR, "Production backend dependencies and Vite build are required.\n");
    exit(2);
}
// Production signatures must never bless an un-audited Composer lockfile,
// even when the prior Setup ZIP and two required push workflows were green.
$securityProcess = @proc_open(
    ['composer', 'audit', '--locked', '--no-interaction'],
    [0 => STDIN, 1 => STDOUT, 2 => STDERR],
    $securityPipes,
    $base
);
if (! is_resource($securityProcess)) {
    fwrite(STDERR, "BLOCKED: Composer locked-security audit could not start.\n");
    exit(2);
}
if (proc_close($securityProcess) !== 0) {
    fwrite(STDERR, "BLOCKED: Composer locked-security audit failed; refusing production signing.\n");
    exit(2);
}

$output = $argv[3];
if (! is_dir($output) && ! mkdir($output, 0700, true)) {
    throw new RuntimeException('Unable to create output directory.');
}
$output = realpath($output);
if ($output === false || str_starts_with($output.'/', $base.'/')) {
    throw new RuntimeException('Output directory must be outside the backend root.');
}
$files = [];
foreach (['app', 'config', 'resources', 'routes', 'vendor', 'public/build', 'database/migrations', 'database/seeders'] as $folder) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base.'/'.$folder, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $entry) {
        if (! $entry->isFile() || $entry->isLink()) {
            throw new RuntimeException('Release contains a non-regular file or symlink.');
        }
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($base) + 1));
        $files[$relative] = $entry->getPathname();
    }
}
foreach (['artisan', 'composer.json', 'composer.lock', 'bootstrap/app.php', 'bootstrap/providers.php', 'public/downloads/aimw-connector.zip', 'aiwm-cpanel-https-pull.php'] as $name) {
    if (! is_file($base.'/'.$name) || is_link($base.'/'.$name)) {
        throw new RuntimeException('Missing release file or unsafe symlink: '.$name);
    }
    $files[$name] = $base.'/'.$name;
}
// The originally installed cPanel 0.0.0 updater predates the exact
// allowed paths for the WordPress connector ZIP and this private Cron file.
// A signed release claiming compatibility with 0.0.0 must NEVER include
// targets rejected by that older verifier: first upgrade the updater itself,
// then permit these extra paths in later releases with minimum >=1.0.0.
$minimumSupportedVersion = (string) (getenv('AIWM_MIN_SUPPORTED_VERSION') ?: '0.0.0');
if (version_compare($minimumSupportedVersion, '1.0.0', '<')) {
    unset($files['public/downloads/aimw-connector.zip'], $files['aiwm-cpanel-https-pull.php']);
}
// The deployed 0.0.0 SystemUpdateService::validatePath rejects any resources/
// path containing the word "private". These files are frontend TS source/tests,
// not deployable runtime assets: Vite already compiled their behavior into
// public/build/manifest.json and its hashed assets. Never weaken the live
// allowlist or ask an owner to edit production PHP to bypass this protection.
foreach (array_keys($files) as $name) {
    if (str_starts_with($name, 'resources/js/') && str_contains($name, 'private')) {
        unset($files[$name]);
    }
}
// Treat future private server-side views/resources as a release blocker rather
// than silently dropping functionality from an apparently complete update.
foreach (array_keys($files) as $name) {
    if (str_starts_with($name, 'resources/') && str_contains($name, 'private')) {
        throw new RuntimeException('Private Laravel resource cannot be safely updated: '.$name);
    }
}
ksort($files, SORT_STRING);
if (count($files) > 25000) {
    throw new RuntimeException('Too many files for the shared-host release channel.');
}
$packageName = 'AIWMWeb-Laravel-update.zip';
$zip = new ZipArchive;
if ($zip->open($output.'/'.$packageName, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create ZIP.');
}
$hashes = [];
$total = 0;
foreach ($files as $name => $absolute) {
    if (strlen($name) > 240 || ! preg_match('#^[A-Za-z0-9_./@+~-]+$#D', $name) ||
        preg_match('#(^|/)(\.{1,2}|\.env|\.git)(/|$)#i', $name) ||
        preg_match('#^(storage|app/storage|public/storage)(/|$)#i', $name)) {
        throw new RuntimeException('Unsafe release path: '.$name);
    }
    $size = filesize($absolute);
    $total += $size;
    if ($size > 26214400 || $total > 314572800) {
        throw new RuntimeException('Uncompressed size exceeds limit.');
    }
    if (! $zip->addFile($absolute, $name)) {
        throw new RuntimeException('Cannot add release file: '.$name);
    }
    $hashes[$name] = hash_file('sha256', $absolute);
}
if (! $zip->close() || filesize($output.'/'.$packageName) > 157286400) {
    throw new RuntimeException('ZIP exceeds release limits.');
}
$minimumSupportedVersion = (string) (getenv('AIWM_MIN_SUPPORTED_VERSION') ?: '0.0.0');
if (! preg_match('/^[0-9]+[.][0-9]+[.][0-9]+$/D', $minimumSupportedVersion) ||
    version_compare($minimumSupportedVersion, $argv[1], '>')) {
    throw new RuntimeException('Invalid minimum supported upgrade version.');
}
$schemaMigrations = array_values(array_filter(
    array_keys($hashes),
    static fn (string $name): bool => str_starts_with($name, 'database/migrations/'),
));
$manifest = [
    'product' => 'LARAVEL_AIWMWEB',
    'version' => $argv[1],
    'minimum_supported_version' => $minimumSupportedVersion,
    'migration_policy' => $schemaMigrations === [] ? 'none' : 'forward_only',
    'schema_migrations' => $schemaMigrations,
    'sha' => $argv[2],
    'package' => $packageName,
    'sha256' => hash_file('sha256', $output.'/'.$packageName),
    'files' => $hashes,
];
$json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
file_put_contents($output.'/AIWMWeb-Laravel-release.json', $json);
file_put_contents(
    $output.'/AIWMWeb-Laravel-release.sig',
    base64_encode(sodium_crypto_sign_detached($json, $private))."\n"
);
$bundleName = 'AIWMWeb-Laravel-signed-bundle.zip';
$bundle = new ZipArchive;

if ($bundle->open($output.'/'.$bundleName, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create one-file signed update bundle.');
}

foreach (['AIWMWeb-Laravel-release.json', 'AIWMWeb-Laravel-release.sig', $packageName] as $assetName) {
    if (! $bundle->addFile($output.'/'.$assetName, $assetName)) {
        throw new RuntimeException('Cannot include a signed asset in upload bundle.');
    }

    if ($assetName === $packageName) {
        $bundle->setCompressionName($assetName, ZipArchive::CM_STORE);
    }
}

if (! $bundle->close() || filesize($output.'/'.$bundleName) > 167772160) {
    throw new RuntimeException('Signed upload bundle exceeds 160 MiB.');
}

fwrite(STDOUT, "Built signed release ".$argv[1]." @ ".$argv[2]." (".count($hashes)." files). GitHub: publish the three individual assets. Admin upload: use ".$bundleName.". Do not expose the secret signing key.\n");

