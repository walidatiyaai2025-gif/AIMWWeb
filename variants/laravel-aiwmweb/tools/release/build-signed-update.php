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
$output = $argv[3];
if (! is_dir($output) && ! mkdir($output, 0700, true)) {
    throw new RuntimeException('Unable to create output directory.');
}
$output = realpath($output);
if ($output === false || str_starts_with($output.'/', $base.'/')) {
    throw new RuntimeException('Output directory must be outside the backend root.');
}
$files = [];
foreach (['app', 'config', 'resources', 'routes', 'vendor', 'public/build'] as $folder) {
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
foreach (['artisan', 'composer.json', 'composer.lock', 'bootstrap/app.php', 'bootstrap/providers.php'] as $name) {
    $files[$name] = $base.'/'.$name;
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
        preg_match('#(^|/)(\.{1,2}|\.env|storage|\.git)(/|$)#i', $name)) {
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
$manifest = [
    'product' => 'LARAVEL_AIWMWEB',
    'version' => $argv[1],
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

