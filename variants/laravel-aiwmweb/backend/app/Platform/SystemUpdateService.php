<?php

namespace App\Platform;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Platform-wide release updater. Trust comes only from a detached Ed25519 signature
 * over the exact manifest bytes, never from GitHub metadata or a browser parameter.
 */
final class SystemUpdateService
{
    private const RELEASE_API = 'https://api.github.com/repos/walidatiyaai2025-gif/AIMWWeb/releases/latest';

    private const MANIFEST = 'AIWMWeb-Laravel-release.json';

    private const SIGNATURE = 'AIWMWeb-Laravel-release.sig';

    private const PACKAGE = 'AIWMWeb-Laravel-update.zip';

    private const MAX_PACKAGE = 157286400;

    private const MAX_FILES = 25000;

    private const MAX_UNCOMPRESSED = 314572800;

    public function state(): array
    {
        $receipt = $this->readJson($this->dataPath('installed.json'));

        return [
            'enabled' => (bool) config('system_updates.enabled'),
            'configured' => $this->publicKey() !== null,
            'version' => $receipt['version'] ?? (string) config('system_updates.current_version', '0.0.0'),
            'sha' => $receipt['sha'] ?? 'unknown',
            'installed_at' => $receipt['installed_at'] ?? null,
            'previous' => $receipt['previous'] ?? null,
        ];
    }

    public function latest(): array
    {
        $this->assertReady();
        try {
            $release = $this->releaseMetadataRequest()->acceptJson()->get(self::RELEASE_API);
        } catch (ConnectionException) {
            throw new RuntimeException('Approved GitHub release feed timed out or failed; retry later.');
        }

        if (! $release->successful()) {
            throw new RuntimeException('Approved GitHub release feed is unavailable.');
        }

        $data = $release->json();

        if (! is_array($data) || ($data['draft'] ?? true) || ($data['prerelease'] ?? true)) {
            throw new RuntimeException('No stable, published release is available.');
        }

        $assets = [];

        foreach (($data['assets'] ?? []) as $asset) {
            if (is_array($asset) && in_array($asset['name'] ?? null, [self::MANIFEST, self::SIGNATURE, self::PACKAGE], true)) {
                $name = $asset['name'];

                if (isset($assets[$name])) {
                    throw new RuntimeException('Duplicate release asset.');
                }

                $url = (string) ($asset['browser_download_url'] ?? '');
                $prefix = 'https://github.com/walidatiyaai2025-gif/AIMWWeb/releases/download/';

                if (! str_starts_with($url, $prefix) || ! str_ends_with($url, '/'.$name)) {
                    throw new RuntimeException('Release asset URL is not approved.');
                }
                $assets[$name] = ['url' => $url, 'size' => (int) ($asset['size'] ?? 0)];
            }
        }

        foreach ([self::MANIFEST, self::SIGNATURE, self::PACKAGE] as $name) {
            if (! isset($assets[$name])) {
                throw new RuntimeException('Release is missing a required signed artifact.');
            }
        }

        if ($assets[self::MANIFEST]['size'] > 4194304 ||
            $assets[self::SIGNATURE]['size'] > 4096 ||
            $assets[self::PACKAGE]['size'] < 1 ||
            $assets[self::PACKAGE]['size'] > self::MAX_PACKAGE) {
            throw new RuntimeException('Release asset size is outside the approved limits.');
        }

        $manifestBytes = $this->downloadSmall($assets[self::MANIFEST]['url'], 4194304);
        $signatureBytes = $this->downloadSmall($assets[self::SIGNATURE]['url'], 4096);
        $manifest = $this->verifySignedManifest($manifestBytes, $signatureBytes);

        $current = $this->state()['version'];
        $available = version_compare($manifest['version'], $current, '>');

        return [
            'version' => $manifest['version'],
            'sha' => $manifest['sha'],
            'available' => $available,
            'current' => $current,
            'manifest' => $manifest,
            'package_asset' => $assets[self::PACKAGE],
        ];
    }

    /**
     * Dry-run signed ZIP verification: never enters maintenance or changes application
     * code, database, installed version, or deployment files. All staging is private.
     *
     * @return array{version: string, sha: string, file_count: int}
     */
    public function verifyUpload(UploadedFile $uploadedBundle): array
    {
        $this->assertReady();

        $work = $this->dataPath('work/verify-'.bin2hex(random_bytes(12)));
        if (! mkdir($work, 0700, true)) {
            throw new RuntimeException('Cannot create private verification staging.');
        }

        try {
            $release = $this->unpackUploadedBundle($uploadedBundle, $work.'/package.zip');
            if (! $release['available']) {
                throw new RuntimeException('Only newer signed releases can be verified for installation.');
            }

            $files = $this->stageZip(
                $work.'/package.zip',
                $work.'/staged',
                $release['manifest']['files'],
            );

            return [
                'version' => $release['version'],
                'sha' => $release['sha'],
                'file_count' => count($files),
            ];
        } finally {
            $this->removeTree($work);
        }
    }

    public function install(int $actorId, ?UploadedFile $uploadedBundle = null): array
    {
        $this->assertReady();
        $folder = $this->dataPath('');

        if (! is_dir($folder) && ! mkdir($folder, 0700, true) && ! is_dir($folder)) {
            throw new RuntimeException('Cannot initialize private update storage.');
        }

        $lock = fopen($this->dataPath('install.lock'), 'c');

        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another installation is in progress.');
        }

        $work = null;

        try {
            $id = bin2hex(random_bytes(12));
            $work = $this->dataPath('work/'.$id);

            if (! mkdir($work, 0700, true)) {
                throw new RuntimeException('Unable to create staging directory.');
            }

            $zipPath = $work.'/package.zip';

            if ($uploadedBundle !== null) {
                // Only signed three-part release bundles are accepted; never raw ZIP source.
                $release = $this->unpackUploadedBundle($uploadedBundle, $zipPath);
            } else {
                $release = $this->latest();
                $asset = $release['package_asset'];
                $response = Http::timeout(180)->withOptions(['sink' => $zipPath])->get($asset['url']);

                if (! $response->successful() || ! is_file($zipPath) ||
                    filesize($zipPath) < 1 || filesize($zipPath) > self::MAX_PACKAGE ||
                    ! hash_equals($release['manifest']['sha256'], hash_file('sha256', $zipPath))) {
                    throw new RuntimeException('Package download or SHA-256 verification failed.');
                }
            }

            if (! $release['available']) {
                throw new RuntimeException('This release is not newer than the installed version.');
            }

            $files = $this->stageZip($zipPath, $work.'/staged', $release['manifest']['files']);
            $migrationFiles = $release['manifest']['schema_migrations'] ?? [];
            // An update must never mutate code before a consistent, encrypted,
            // independently verified database snapshot has been completed.
            // On unsupported shared hosting the operation fails closed here.
            $databaseBackup = app(VerifiedMysqlBackupService::class)->backupNow($actorId);
            $backup = $this->dataPath('backups/'.$id);
            $this->backupFiles($files, $backup);
            $previous = $this->readJson($this->dataPath('installed.json'));
            $receipt = [
                'id' => $id,
                'version' => $release['version'],
                'sha' => $release['sha'],
                'actor_id' => $actorId,
                'database_backup' => $databaseBackup,
                'schema_migrations' => $migrationFiles,
                'installed_at' => now()->toIso8601String(),
                'previous' => $previous ? [
                    'version' => $previous['version'] ?? 'unknown',
                    'sha' => $previous['sha'] ?? 'unknown',
                ] : null,
            ];

            $maintenanceActive = false;
            $recoveryVerified = true;
            $migrationStarted = false;

            try {
                if (Artisan::call('down', ['--retry' => 30]) !== 0) {
                    throw new RuntimeException('Could not enter maintenance mode.');
                }
                $maintenanceActive = true;

                foreach ($files as $name => $hash) {
                    $this->applyStagedFile($name, $work.'/staged/'.$name, $hash, $id);
                }

                if ($migrationFiles !== []) {
                    // From this point a migration may have committed irreversible DDL.
                    // Never automatically roll back only the PHP files afterwards.
                    $migrationStarted = true;
                    if (Artisan::call('migrate', ['--force' => true]) !== 0) {
                        throw new RuntimeException('Signed database migrations failed.');
                    }
                }

                if (Artisan::call('optimize:clear') !== 0) {
                    throw new RuntimeException('Could not clear Laravel caches.');
                }
                if ((int) DB::connection()->selectOne('SELECT 1 AS health')->health !== 1) {
                    throw new RuntimeException('Post-upgrade database health check failed.');
                }
                $this->atomicJson($this->dataPath('installed.json'), $receipt);
            } catch (Throwable $e) {
                if ($migrationStarted) {
                    $recoveryVerified = false;
                    throw new RuntimeException(
                        'Database migrations may have committed; site remains in maintenance. Do not restore files alone. Use the verified isolated database snapshot and assisted recovery.',
                        0,
                        $e,
                    );
                }

                try {
                    $this->restoreBackup($backup);
                } catch (Throwable $restoreFailure) {
                    // Never serve a potentially mixed-version application after a
                    // failed rollback. Preserve maintenance mode for manual recovery.
                    $recoveryVerified = false;

                    throw new RuntimeException(
                        'Update rollback could not be verified; maintenance mode remains enabled. Manual recovery is required.',
                        0,
                        $restoreFailure,
                    );
                }

                throw $e;
            } finally {
                if ($maintenanceActive && $recoveryVerified && Artisan::call('up') !== 0) {
                    throw new RuntimeException('Could not exit maintenance mode; manual recovery is required.');
                }
            }
            $this->history(['status' => 'installed'] + $receipt);

            return $receipt;
        } catch (Throwable $e) {
            $this->history(['status' => 'failed', 'actor_id' => $actorId, 'error' => $e->getMessage(), 'at' => now()->toIso8601String()]);

            throw $e;
        } finally {
            if (is_string($work)) {
                $this->removeTree($work);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Unpack a one-file transport wrapper. The verified INNER ZIP remains the
     * exact artifact covered by the signed SHA256 and per-file hashes.
     *
     * @return array{version:string,sha:string,available:bool,manifest:array}
     */
    private function unpackUploadedBundle(UploadedFile $bundle, string $destination): array
    {
        $source = $bundle->getRealPath();

        if (! $bundle->isValid() || $source === false || ! is_file($source) ||
            filesize($source) < 1 || filesize($source) > 167772160) {
            throw new RuntimeException('Uploaded update ZIP is missing or exceeds the 160 MiB limit.');
        }

        $archive = new ZipArchive;

        if ($archive->open($source) !== true) {
            throw new RuntimeException('Uploaded update is not a ZIP archive.');
        }

        try {
            $names = [self::MANIFEST, self::SIGNATURE, self::PACKAGE];

            if ($archive->numFiles !== count($names)) {
                throw new RuntimeException('The update ZIP must contain exactly three signed release assets.');
            }

            $maxSizes = [self::MANIFEST => 4194304, self::SIGNATURE => 4096, self::PACKAGE => self::MAX_PACKAGE];
            $seen = [];

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $stat = $archive->statIndex($index);
                $name = $stat['name'] ?? '';

                if (! in_array($name, $names, true) || isset($seen[$name]) ||
                    (int) ($stat['size'] ?? -1) < 1 ||
                    (int) ($stat['size'] ?? -1) > $maxSizes[$name]) {
                    throw new RuntimeException('Uploaded ZIP contains an unexpected, duplicate or oversized asset.');
                }

                $seen[$name] = true;
                $archive->getExternalAttributesIndex($index, $opsys, $attributes);

                if ($opsys === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('Symlinks are forbidden in update bundles.');
                }
            }

            if (count($seen) !== count($names)) {
                throw new RuntimeException('Uploaded ZIP is missing signed release assets.');
            }

            $manifestBytes = $archive->getFromName(self::MANIFEST);
            $signatureBytes = $archive->getFromName(self::SIGNATURE);

            if ($manifestBytes === false || $signatureBytes === false) {
                throw new RuntimeException('Cannot read signed release metadata from uploaded ZIP.');
            }

            $manifest = $this->verifySignedManifest($manifestBytes, $signatureBytes);
            $input = $archive->getStream(self::PACKAGE);
            $output = fopen($destination, 'wb');

            if (! $input || ! $output) {
                if ($input) {
                    fclose($input);
                }

                if ($output) {
                    fclose($output);
                }

                throw new RuntimeException('Cannot stage the signed package.');
            }

            try {
                $count = stream_copy_to_stream($input, $output, self::MAX_PACKAGE + 1);
            } finally {
                fclose($input);
                fclose($output);
            }

            if ($count !== $archive->statName(self::PACKAGE)['size'] ||
                ! is_file($destination) || filesize($destination) > self::MAX_PACKAGE ||
                ! hash_equals($manifest['sha256'], hash_file('sha256', $destination))) {
                throw new RuntimeException('Uploaded package does not match the signed SHA256.');
            }

            $current = $this->state()['version'];

            return [
                'version' => $manifest['version'],
                'sha' => $manifest['sha'],
                'available' => version_compare($manifest['version'], $current, '>'),
                'manifest' => $manifest,
            ];
        } finally {
            $archive->close();
        }
    }

    /** Validate identical signature + manifest contract for GitHub and uploaded bundles. */
    private function verifySignedManifest(string $manifestBytes, string $signatureBytes): array
    {
        $signature = base64_decode(trim($signatureBytes), true);

        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES ||
            ! sodium_crypto_sign_verify_detached($signature, $manifestBytes, $this->publicKey())) {
            throw new RuntimeException('Release manifest signature could not be verified.');
        }

        $manifest = json_decode($manifestBytes, true);

        if (! is_array($manifest) ||
            ($manifest['product'] ?? '') !== 'LARAVEL_AIWMWEB' ||
            ! preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', (string) ($manifest['version'] ?? '')) ||
            ! preg_match('/^[a-f0-9]{40}$/D', (string) ($manifest['sha'] ?? '')) ||
            ! preg_match('/^[a-f0-9]{64}$/D', (string) ($manifest['sha256'] ?? '')) ||
            ($manifest['package'] ?? '') !== self::PACKAGE ||
            ! is_array($manifest['files'] ?? null) || $manifest['files'] === [] ||
            count($manifest['files']) > self::MAX_FILES) {
            throw new RuntimeException('Release manifest is invalid.');
        }

        foreach ($manifest['files'] as $name => $hash) {
            if (! is_string($name) || ! is_string($hash) ||
                ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new RuntimeException('Release file manifest is invalid.');
            }

            $this->validatePath($name);
        }

        $minimum = (string) ($manifest['minimum_supported_version'] ?? '0.0.0');
        if (! preg_match('/^[0-9]+[.][0-9]+[.][0-9]+$/D', $minimum) ||
            version_compare($minimum, $manifest['version'], '>') ||
            version_compare($this->state()['version'], $minimum, '<')) {
            throw new RuntimeException('Update is incompatible with the installed version.');
        }
        $migrationFiles = array_values(array_filter(
            array_keys($manifest['files']),
            static fn (string $name): bool => str_starts_with($name, 'database/migrations/'),
        ));
        $declared = $manifest['schema_migrations'] ?? [];
        if (! is_array($declared) || array_values($declared) !== $migrationFiles ||
            ($manifest['migration_policy'] ?? ($migrationFiles === [] ? 'none' : null)) !==
                ($migrationFiles === [] ? 'none' : 'forward_only')) {
            throw new RuntimeException('Signed database migration inventory or policy is invalid.');
        }

        return $manifest;
    }

    private function assertReady(): void
    {
        if (! config('system_updates.enabled') || ! function_exists('sodium_crypto_sign_verify_detached') || $this->publicKey() === null) {
            throw new RuntimeException('Signed system updates are not configured on this server.');
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZIP extension is required for system updates.');
        }
    }

    private function publicKey(): ?string
    {
        if (! defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')) {
            return null;
        }

        $key = base64_decode(trim((string) config('system_updates.public_key', '')), true);

        return $key !== false && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $key : null;
    }

    /**
     * Bounded transport retry only. Never retry signature failures or malformed
     * manifests, and never disable TLS checks for an unreliable cPanel network.
     */
    private function releaseMetadataRequest(): PendingRequest
    {
        $connect = max(3, min(15, (int) config('system_updates.metadata_connect_timeout', 8)));
        $total = max($connect, min(90, (int) config('system_updates.metadata_timeout', 60)));

        return Http::connectTimeout($connect)
            ->timeout($total)
            ->withOptions(['allow_redirects' => [
                'max' => 3,
                'protocols' => ['https'],
                'on_redirect' => static function ($request, $response, $uri): void {
                    // GitHub signed release assets may redirect to its official CDN only.
                    // Never permit an arbitrary HTTPS redirect to private networks.
                    if (strtolower((string) $uri->getScheme()) !== 'https' ||
                        $uri->getUserInfo() !== '' ||
                        $uri->getPort() !== null ||
                        ! in_array(strtolower((string) $uri->getHost()), [
                            'github.com',
                            'api.github.com',
                            'release-assets.githubusercontent.com',
                            'objects.githubusercontent.com',
                        ], true)) {
                        throw new RuntimeException('Unapproved release metadata redirect destination.');
                    }
                },
            ]])
            ->retry(3, static fn (int $attempt): int => $attempt === 1 ? 250 : 750, static function (Throwable $exception): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                return $exception instanceof RequestException
                    && in_array($exception->response->status(), [429, 502, 503, 504], true);
            }, throw: false);
    }

    private function downloadSmall(string $url, int $limit): string
    {
        try {
            $response = $this->releaseMetadataRequest()
                ->withOptions([
                    // Abort oversized responses while they are being transferred,
                    // not only after PHP has buffered the complete body.
                    'on_headers' => static function ($response) use ($limit): void {
                        $length = $response->getHeaderLine('Content-Length');
                        if ($length !== '' && ctype_digit($length) && (float) $length > $limit) {
                            throw new RuntimeException('Release metadata exceeds the allowed size.');
                        }
                    },
                    'progress' => static function ($total, $downloaded) use ($limit): void {
                        if ($downloaded > $limit) {
                            throw new RuntimeException('Release metadata exceeds the allowed size.');
                        }
                    },
                ])
                ->get($url);
        } catch (ConnectionException) {
            throw new RuntimeException('Release metadata connection timed out or failed; retry later or upload a signed update bundle.');
        }

        if (! $response->successful() || strlen($response->body()) > $limit) {
            throw new RuntimeException('Release metadata download failed.');
        }

        return $response->body();
    }

    /** @return array<string, string> verified relative path => sha256 */
    private function stageZip(string $path, string $stage, array $expected): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Package is not a valid ZIP archive.');
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles !== count($expected) || $zip->numFiles > self::MAX_FILES) {
                throw new RuntimeException('Package file count does not match signed manifest.');
            }

            $total = 0;
            $seen = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'] ?? '';
                $this->validatePath($name);

                if (isset($seen[$name]) || ! isset($expected[$name]) ||
                    ! preg_match('/^[a-f0-9]{64}$/D', (string) $expected[$name])) {
                    throw new RuntimeException('Package contains an unexpected or duplicate file.');
                }
                $seen[$name] = true;
                $total += (int) ($stat['size'] ?? 0);

                if ($total > self::MAX_UNCOMPRESSED || (int) ($stat['size'] ?? 0) > 26214400) {
                    throw new RuntimeException('Package exceeds extraction limits.');
                }
                $zip->getExternalAttributesIndex($i, $opsys, $attrs);

                if ($opsys === ZipArchive::OPSYS_UNIX && (($attrs >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('Symbolic links are forbidden in update packages.');
                }

                $target = $stage.'/'.$name;
                $parent = dirname($target);

                if (! is_dir($parent) && ! mkdir($parent, 0700, true) && ! is_dir($parent)) {
                    throw new RuntimeException('Cannot create private staging path.');
                }

                $in = $zip->getStream($name);
                $out = fopen($target, 'wb');

                if (! $in || ! $out) {
                    throw new RuntimeException('Cannot stage package file.');
                }

                try {
                    $copied = stream_copy_to_stream($in, $out, 26214401);
                } finally {
                    fclose($in);
                    fclose($out);
                }

                if ($copied !== $stat['size'] || ! hash_equals($expected[$name], hash_file('sha256', $target))) {
                    throw new RuntimeException('Signed package file verification failed.');
                }

                $destination = $this->targetPath($name);
                $this->assertSafeDestination($destination);
            }

            return $expected;
        } finally {
            $zip->close();
        }
    }

    /**
     * Revalidate the destination at the instant files are applied, not only
     * during ZIP staging and backup. A swapped directory or temporary link
     * must not redirect an otherwise valid signed update outside the app.
     */
    private function applyStagedFile(string $name, string $source, string $hash, string $id): void
    {
        $this->validatePath($name);
        $target = $this->targetPath($name);
        $this->assertSafeDestination($target);
        $temp = dirname($target).'/.aiwm-update-'.$id;

        // A leftover file or attacker-planted link cannot be used as the
        // staging destination. Never follow an existing temporary pathname.
        if (file_exists($temp) || is_link($temp)) {
            throw new RuntimeException('Unsafe or occupied update temporary path.');
        }

        // Exclusive creation closes the check-to-copy gap: copy() would
        // follow an attacker-created symlink between the check above and open.
        $input = @fopen($source, 'rb');

        if ($input === false) {
            throw new RuntimeException('Cannot read verified staged update file.');
        }

        // Give only public release assets web-readable permissions at
        // exclusive file creation. This avoids a pathname-based chmod race
        // after writing the verified asset and restores the original umask
        // immediately for all private Laravel storage/backup operations.
        $priorUmask = str_starts_with($name, 'public/') ? umask(0022) : null;
        try {
            $output = @fopen($temp, 'xb');
        } finally {
            if ($priorUmask !== null) {
                umask($priorUmask);
            }
        }

        if ($output === false) {
            fclose($input);

            throw new RuntimeException('Unsafe or occupied update temporary path.');
        }

        try {
            try {
                $copied = stream_copy_to_stream($input, $output, 26214401);

                if ($copied === false || ! fflush($output)) {
                    throw new RuntimeException('File replacement verification failed.');
                }
            } finally {
                fclose($input);
                fclose($output);
            }

            // Recheck both paths after the exclusive write and before rename.
            $this->assertSafeDestination($target);
            $this->assertSafeDestination($temp);

            if (! rename($temp, $target) ||
                ! hash_equals($hash, (string) hash_file('sha256', $target))) {
                throw new RuntimeException('File replacement verification failed.');
            }
        } finally {
            if (file_exists($temp) || is_link($temp)) {
                @unlink($temp);
            }
        }
    }

    private function validatePath(string $path): void
    {
        if ($path === '' || strlen($path) > 240 || str_contains($path, '\\') ||
            str_starts_with($path, '/') || preg_match('#(^|/)(\.{1,2}|\.env|\.git)(/|$)#i', $path) ||
        preg_match('#^(storage|app/storage|public/storage)(/|$)#i', $path) ||
            ! preg_match('#^[a-zA-Z0-9_./@+~-]+$#D', $path)) {
            throw new RuntimeException('Unsafe file path in update package.');
        }

        $allowed = ['app/', 'config/', 'resources/', 'routes/', 'vendor/', 'public/build/', 'database/migrations/', 'database/seeders/'];

        if (! in_array($path, ['composer.json', 'composer.lock', 'artisan', 'bootstrap/app.php', 'bootstrap/providers.php', 'public/downloads/aimw-connector.zip', 'aiwm-cpanel-https-pull.php'], true) &&
            ! $this->hasAllowedPrefix($allowed, $path)) {
            throw new RuntimeException('File is outside the allowed update directories.');
        }

        if (str_starts_with($path, 'database/migrations/') &&
            ! preg_match('#^database/migrations/[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[A-Za-z0-9_]+[.]php$#D', $path)) {
            throw new RuntimeException('Unsafe migration filename in signed update.');
        }
        if (str_starts_with($path, 'database/seeders/') &&
            ! preg_match('#^database/seeders/[A-Za-z0-9_/]+[.]php$#D', $path)) {
            throw new RuntimeException('Unsafe production seeder filename in signed update.');
        }

        if (str_starts_with($path, 'resources/') && str_contains($path, 'private')) {
            throw new RuntimeException('Private runtime paths cannot be updated.');
        }
    }

    private function hasAllowedPrefix(array $prefixes, string $path): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function publicRoot(): string
    {
        $configured = trim((string) config('system_updates.public_root', ''));

        return rtrim($configured !== '' ? $configured : public_path(), '/');
    }

    private function targetPath(string $name): string
    {
        return str_starts_with($name, 'public/')
            ? $this->publicRoot().'/'.substr($name, strlen('public/'))
            : base_path($name);
    }

    private function assertSafeDestination(string $target): void
    {
        $publicRoot = realpath($this->publicRoot());
        $appRoot = realpath(base_path());
        $isPublic = str_starts_with($target, $this->publicRoot().'/');
        $root = $isPublic ? $publicRoot : $appRoot;

        if ($root === false) {
            throw new RuntimeException('Invalid application root.');
        }

        $relative = substr($target, strlen($isPublic ? $this->publicRoot() : base_path()) + 1);
        $cursor = $root;

        foreach (explode('/', $relative) as $part) {
            $cursor .= '/'.$part;

            if (is_link($cursor)) {
                throw new RuntimeException('Update path crosses a symbolic link.');
            }
        }
    }

    private function backupFiles(array $files, string $backup): void
    {
        if (! mkdir($backup.'/original', 0700, true)) {
            throw new RuntimeException('Cannot create private update backup.');
        }

        $existed = [];
        $hashes = [];

        foreach ($files as $name => $_) {
            $target = $this->targetPath($name);
            $this->assertSafeDestination($target);
            $existed[$name] = is_file($target);

            if ($existed[$name]) {
                $destination = $backup.'/original/'.$name;

                if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0700, true) && ! is_dir(dirname($destination))) {
                    throw new RuntimeException('Cannot allocate update backup.');
                }

                $originalHash = hash_file('sha256', $target);
                if (! is_string($originalHash) || ! copy($target, $destination) ||
                    ! hash_equals($originalHash, (string) hash_file('sha256', $destination))) {
                    throw new RuntimeException('Verified backup failed for '.$name);
                }
                $hashes[$name] = $originalHash;
            } elseif (file_exists($target)) {
                throw new RuntimeException('Update would overwrite a non-file.');
            }

            if (! is_writable(dirname($target))) {
                // A missing directory may be created, but never silently outside the app root.

                if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0755, true) && ! is_dir(dirname($target))) {
                    throw new RuntimeException('Destination directory is not writable.');
                }

                if (! is_writable(dirname($target))) {
                    throw new RuntimeException('Destination directory is not writable.');
                }
            }
        }
        $this->atomicJson($backup.'/hashes.json', $hashes);
        $this->atomicJson($backup.'/files.json', $existed);
    }

    private function restoreBackup(string $backup): void
    {
        $existing = $this->readJson($backup.'/files.json');
        $hashes = $this->readJson($backup.'/hashes.json');

        if ($existing === null || $hashes === null) {
            throw new RuntimeException('Rollback inventory is missing; manual recovery is required.');
        }

        foreach ($existing as $name => $wasPresent) {
            // Inventory is persisted state, not a trust boundary: an interrupted
            // update or tampered backup must never turn rollback into an
            // arbitrary write/delete outside the signed update allowlist.
            $this->validatePath((string) $name);
            $target = $this->targetPath((string) $name);

            // Recheck symlinks at rollback time. The destination may have been
            // replaced after the initial stage/backup validation.
            $this->assertSafeDestination($target);

            if ($wasPresent) {
                $source = $backup.'/original/'.$name;
                $expected = $hashes[$name] ?? null;
                if (! is_string($expected) || ! preg_match('/^[a-f0-9]{64}$/D', $expected) ||
                    ! is_file($source) || ! hash_equals($expected, (string) hash_file('sha256', $source))) {
                    throw new RuntimeException('Backup integrity verification failed; manual recovery is required.');
                }
                // Never stream directly into the live application file: a
                // short write, full disk or interrupted copy would corrupt it
                // before recovery can be verified. Stage beside the target and
                // atomically replace it only after its signed-backup hash matches.
                $temporary = dirname($target).'/.aiwm-rollback-'.bin2hex(random_bytes(12));
                $input = @fopen($source, 'rb');

                if ($input === false) {
                    throw new RuntimeException('Cannot read verified rollback backup.');
                }

                $output = @fopen($temporary, 'xb');

                if ($output === false) {
                    fclose($input);

                    throw new RuntimeException('Cannot create exclusive rollback staging file.');
                }

                try {
                    try {
                        $copied = stream_copy_to_stream($input, $output, 26214401);

                        if ($copied === false || ! fflush($output)) {
                            throw new RuntimeException('Rollback staging failed; live file was not replaced.');
                        }
                    } finally {
                        fclose($input);
                        fclose($output);
                    }

                    $this->assertSafeDestination($target);
                    $this->assertSafeDestination($temporary);

                    if (! hash_equals($expected, (string) hash_file('sha256', $temporary))) {
                        throw new RuntimeException('Rollback staging integrity failed; live file was not replaced.');
                    }

                    if (! rename($temporary, $target) ||
                        ! hash_equals($expected, (string) hash_file('sha256', $target))) {
                        throw new RuntimeException('Automatic rollback failed; use the saved backup.');
                    }
                } finally {
                    if (file_exists($temporary) || is_link($temporary)) {
                        @unlink($temporary);
                    }
                }
            } else {
                // A new file introduced by a failed update must be absent
                // before automatic recovery can be considered successful.
                // Silencing unlink errors can otherwise leave mixed-version
                // executable code while maintenance mode is released.
                if ((file_exists($target) || is_link($target)) && ! @unlink($target)) {
                    throw new RuntimeException('Automatic rollback could not remove a new file; manual recovery is required.');
                }

                if (file_exists($target) || is_link($target)) {
                    throw new RuntimeException('Automatic rollback left a new file behind; manual recovery is required.');
                }
            }
        }
    }

    private function dataPath(string $name): string
    {
        return storage_path('app/system-updates/'.ltrim($name, '/'));
    }

    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    private function atomicJson(string $path, array $data): void
    {
        $temp = $path.'.tmp.'.bin2hex(random_bytes(4));

        if (file_put_contents($temp, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX) === false ||
            ! rename($temp, $path)) {
            throw new RuntimeException('Cannot save the update receipt.');
        }
    }

    private function history(array $row): void
    {
        $file = $this->dataPath('history.jsonl');

        if (! is_dir(dirname($file))) {
            @mkdir(dirname($file), 0700, true);
        }

        @file_put_contents($file, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir) || is_link($dir)) {
            return;
        }

        foreach (new \FilesystemIterator($dir) as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                $this->removeTree($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($dir);
    }
}
