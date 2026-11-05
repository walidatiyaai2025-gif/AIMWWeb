<?php

namespace App\Platform;

use Illuminate\Support\Carbon;

final class BuildInformationReadService
{
    public function snapshot(): array
    {
        $version = trim((string) env('APP_VERSION', '0.0.0')) ?: '0.0.0';
        $informational = trim((string) env('APP_INFORMATIONAL_VERSION', $version)) ?: $version;
        $branch = $this->firstNonEmpty([
            env('GITHUB_HEAD_REF'),
            env('GITHUB_REF_NAME'),
            env('BUILD_SOURCEBRANCHNAME'),
        ], 'unknown');
        $commit = $this->firstNonEmpty([
            env('GITHUB_SHA'),
            env('BUILD_SOURCEVERSION'),
        ], 'unknown');
        // The cPanel archive ships without .git or CI environment variables.
        // Use only the build's own private immutable package manifest as provenance.
        $manifestPath = dirname(base_path()).'/MANIFEST.json';
        if (is_file($manifestPath)) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (is_array($manifest) && ($manifest['product'] ?? null) === 'Laravel AIWMWeb') {
                $manifestSha = $manifest['source_sha'] ?? null;
                if ($commit === 'unknown' && is_string($manifestSha)
                    && preg_match('/^[a-f0-9]{40}$/D', $manifestSha) === 1) {
                    $commit = $manifestSha;
                }
                $manifestVersion = $manifest['version'] ?? null;
                if ($version === '0.0.0' && is_string($manifestVersion)
                    && preg_match('/^cpanel-[a-f0-9]{12}$/D', $manifestVersion) === 1) {
                    $version = $manifestVersion;
                    $informational = $manifestVersion;
                }
            }
        }
        if ($commit !== 'unknown') {
            $commit = substr($commit, 0, 12);
        }

        return [
            'version' => $version,
            'informationalVersion' => $informational,
            'branch' => $branch,
            'commit' => $commit,
            'buildTimeUtc' => $this->buildTimeUtc(),
            'assemblyName' => (string) config('app.name', 'Laravel AIWMWeb'),
        ];
    }

    private function firstNonEmpty(array $values, string $fallback): string
    {
        foreach ($values as $value) {
            $clean = trim((string) ($value ?? ''));
            if ($clean !== '') {
                return $clean;
            }
        }

        return $fallback;
    }

    private function buildTimeUtc(): string
    {
        $configured = trim((string) env('BUILD_TIME_UTC', ''));
        if ($configured !== '') {
            return Carbon::parse($configured)->utc()->toIso8601String();
        }

        $artifact = base_path('composer.lock');
        $timestamp = is_file($artifact) ? filemtime($artifact) : false;

        return Carbon::createFromTimestampUTC($timestamp !== false ? $timestamp : 0)->toIso8601String();
    }
}
