<?php

namespace App\Platform;

use Illuminate\Support\Facades\Artisan;
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
        $release = Http::acceptJson()->timeout(12)->get(self::RELEASE_API);
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
        if ($assets[self::MANIFEST]['size'] > 262144 ||
            $assets[self::SIGNATURE]['size'] > 4096 ||
            $assets[self::PACKAGE]['size'] < 1 ||
            $assets[self::PACKAGE]['size'] > self::MAX_PACKAGE) {
            throw new RuntimeException('Release asset size is outside the approved limits.');
        }
        $manifestBytes = $this->downloadSmall($assets[self::MANIFEST]['url'], 262144);
        $signatureBytes = $this->downloadSmall($assets[self::SIGNATURE]['url'], 4096);
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

    public function install(int $actorId): array
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
            $release = $this->latest();
            if (! $release['available']) {
                throw new RuntimeException('This release is not newer than the installed version.');
            }
            $id = bin2hex(random_bytes(12));
            $work = $this->dataPath('work/'.$id);
            if (! mkdir($work, 0700, true)) {
                throw new RuntimeException('Unable to create staging directory.');
            }
            $zipPath = $work.'/package.zip';
            $asset = $release['package_asset'];
            $response = Http::timeout(180)->withOptions(['sink' => $zipPath])->get($asset['url']);
            if (! $response->successful() || ! is_file($zipPath) ||
                filesize($zipPath) < 1 || filesize($zipPath) > self::MAX_PACKAGE ||
                ! hash_equals($release['manifest']['sha256'], hash_file('sha256', $zipPath))) {
                throw new RuntimeException('Package download or SHA-256 verification failed.');
            }
            $files = $this->stageZip($zipPath, $work.'/staged', $release['manifest']['files']);
            $backup = $this->dataPath('backups/'.$id);
            $this->backupFiles($files, $backup);
            $previous = $this->readJson($this->dataPath('installed.json'));
            $receipt = [
                'id' => $id,
                'version' => $release['version'],
                'sha' => $release['sha'],
                'actor_id' => $actorId,
                'installed_at' => now()->toIso8601String(),
                'previous' => $previous ? [
                    'version' => $previous['version'] ?? 'unknown',
                    'sha' => $previous['sha'] ?? 'unknown',
                ] : null,
            ];
            try {
                Artisan::call('down', ['--retry' => 30]);
                foreach ($files as $name => $hash) {
                    $target = $this->targetPath($name);
                    $temp = dirname($target).'/.aiwm-update-'.$id;
                    if (! copy($work.'/staged/'.$name, $temp) || ! rename($temp, $target) ||
                        ! hash_equals($hash, hash_file('sha256', $target))) {
                        @unlink($temp);
                        throw new RuntimeException('File replacement verification failed.');
                    }
                }
                Artisan::call('optimize:clear');
                $this->atomicJson($this->dataPath('installed.json'), $receipt);
            } catch (Throwable $e) {
                $this->restoreBackup($backup);
                throw $e;
            } finally {
                Artisan::call('up');
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
        $key = base64_decode(trim((string) config('system_updates.public_key', '')), true);
        return $key !== false && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $key : null;
    }

    private function downloadSmall(string $url, int $limit): string
    {
        $response = Http::timeout(15)->get($url);
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

    private function validatePath(string $path): void
    {
        if ($path === '' || strlen($path) > 240 || str_contains($path, '\\') ||
            str_starts_with($path, '/') || preg_match('#(^|/)(\.{1,2}|\.env|storage|\.git)(/|$)#i', $path) ||
            ! preg_match('#^[a-zA-Z0-9_./@+~-]+$#D', $path)) {
            throw new RuntimeException('Unsafe file path in update package.');
        }
        $allowed = ['app/', 'config/', 'resources/', 'routes/', 'vendor/', 'public/build/'];
        if (! in_array($path, ['composer.json', 'composer.lock', 'artisan', 'bootstrap/app.php', 'bootstrap/providers.php'], true) &&
            ! $this->hasAllowedPrefix($allowed, $path)) {
            throw new RuntimeException('File is outside the allowed update directories.');
        }
        if (str_starts_with($path, 'resources/') && str_contains($path, 'private')) {
            throw new RuntimeException('Private runtime paths cannot be updated.');
        }
    }

    private function hasAllowedPrefix(array $prefixes, string $path): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) return true;
        }
        return false;
    }

    private function targetPath(string $name): string
    {
        return str_starts_with($name, 'public/')
            ? public_path(substr($name, strlen('public/')))
            : base_path($name);
    }

    private function assertSafeDestination(string $target): void
    {
        $publicRoot = realpath(public_path());
        $appRoot = realpath(base_path());
        $publicPath = rtrim(public_path(), '/').'/';
        $isPublic = str_starts_with($target, $publicPath);
        $root = $isPublic ? $publicRoot : $appRoot;
        if ($root === false) {
            throw new RuntimeException('Invalid application root.');
        }
        $relative = substr($target, strlen($isPublic ? public_path() : base_path()) + 1);
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
        foreach ($files as $name => $_) {
            $target = $this->targetPath($name);
            $this->assertSafeDestination($target);
            $existed[$name] = is_file($target);
            if ($existed[$name]) {
                $destination = $backup.'/original/'.$name;
                if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0700, true) && ! is_dir(dirname($destination))) {
                    throw new RuntimeException('Cannot allocate update backup.');
                }
                if (! copy($target, $destination)) {
                    throw new RuntimeException('Cannot back up '.$name);
                }
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
        $this->atomicJson($backup.'/files.json', $existed);
    }

    private function restoreBackup(string $backup): void
    {
        $existing = $this->readJson($backup.'/files.json');
        if ($existing === null) {
            throw new RuntimeException('Rollback inventory is missing; manual recovery is required.');
        }
        foreach ($existing as $name => $wasPresent) {
            $target = $this->targetPath($name);
            if ($wasPresent) {
                if (! copy($backup.'/original/'.$name, $target)) {
                    throw new RuntimeException('Automatic rollback failed; use the saved backup.');
                }
            } else {
                @unlink($target);
            }
        }
    }

    private function dataPath(string $name): string
    {
        return storage_path('app/system-updates/'.ltrim($name, '/'));
    }

    private function readJson(string $path): ?array
    {
        if (! is_file($path)) return null;
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
        if (! is_dir(dirname($file))) @mkdir(dirname($file), 0700, true);
        @file_put_contents($file, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir) || is_link($dir)) return;
        foreach (new \FilesystemIterator($dir) as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) $this->removeTree($entry->getPathname());
            else @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }
}
