<?php

namespace App\Platform;

use DateTimeImmutable;
use RuntimeException;

final class ReleaseNotesService
{
    private readonly string $path;

    private ?int $lastWriteUtc = null;

    /** @var list<array{version:string,date:?string,title:string,changes:list<string>}> */
    private array $cache = [];

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? base_path('RELEASE_NOTES.md');
    }

    /**
     * Adaptation of canonical operation AIMW-PLAT-15C5517022
     * (ReleaseNotesService.GetAll).
     *
     * @return list<array{version:string,date:?string,title:string,changes:list<string>}>
     */
    public function getAll(): array
    {
        if (! is_file($this->path)) {
            $this->cache = [];
            $this->lastWriteUtc = null;

            return [];
        }

        clearstatcache(true, $this->path);
        $lastWriteUtc = filemtime($this->path);

        if ($this->cache !== [] && $lastWriteUtc !== false && $lastWriteUtc === $this->lastWriteUtc) {
            return $this->cache;
        }

        $lines = @file($this->path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Release notes file exists but could not be read.');
        }

        $this->cache = $this->parse($lines);
        $this->lastWriteUtc = $lastWriteUtc === false ? null : $lastWriteUtc;

        return $this->cache;
    }

    /**
     * Adaptation of canonical operation AIMW-PLAT-57B1A0F5E3
     * (ReleaseNotesService.GetCurrent).
     *
     * @return array{version:string,date:?string,title:string,changes:list<string>}|null
     */
    public function getCurrent(string $version): ?array
    {
        $normalized = ltrim(trim($version), 'vV');

        foreach ($this->getAll() as $release) {
            if (strcasecmp($release['version'], $normalized) === 0) {
                return $release;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $lines
     * @return list<array{version:string,date:?string,title:string,changes:list<string>}>
     */
    private function parse(array $lines): array
    {
        $releases = [];
        $version = null;
        $date = null;
        $title = null;
        $changes = [];

        $flush = function () use (&$releases, &$version, &$date, &$title, &$changes): void {
            if ($version === null || trim($version) === '') {
                return;
            }

            $releases[] = [
                'version' => $version,
                'date' => $date,
                'title' => $title ?? 'Version '.$version,
                'changes' => array_values($changes),
            ];
            $changes = [];
        };

        foreach ($lines as $raw) {
            $line = trim($raw);

            if (str_starts_with($line, '## ')) {
                $flush();

                [$version, $date, $title] = $this->parseHeader(trim(substr($line, 3)));

                continue;
            }

            if ($version !== null && (str_starts_with($line, '- ') || str_starts_with($line, '* '))) {
                $changes[] = trim(substr($line, 2));
            }
        }

        $flush();

        return $releases;
    }

    /**
     * Mirrors the source grammar:
     * v?VERSION [dash DATE] [dash-or-colon TITLE].
     *
     * When that grammar does not match, the source keeps the whole heading
     * (minus a leading v/V) as the version and leaves date/title empty.
     *
     * @return array{0:string,1:?string,2:?string}
     */
    private function parseHeader(string $header): array
    {
        if (preg_match('/^v?(?<version>\d+(?:\.\d+){1,3})(?<suffix>.*)$/u', $header, $matches) !== 1) {
            return [ltrim($header, 'vV'), null, null];
        }

        $version = $matches['version'];
        $suffix = $matches['suffix'] ?? '';
        if ($suffix === '') {
            return [$version, null, null];
        }

        if (preg_match('/^\s*:\s*(?<title>.+)$/u', $suffix, $titleMatch) === 1) {
            return [$version, null, trim($titleMatch['title'])];
        }

        if (preg_match('/^\s*[-–—]\s*(?<rest>.+)$/u', $suffix, $separatorMatch) !== 1) {
            return [ltrim($header, 'vV'), null, null];
        }

        $rest = $separatorMatch['rest'];
        if (preg_match(
            '/^(?<date>\d{4}-\d{2}-\d{2})(?:\s*[-–—:]\s*(?<title>.+))?$/u',
            $rest,
            $dateMatch,
        ) === 1) {
            $title = isset($dateMatch['title']) && trim($dateMatch['title']) !== ''
                ? trim($dateMatch['title'])
                : null;

            return [$version, $this->parseDate($dateMatch['date']), $title];
        }

        // If the dash-separated suffix is not exactly a date expression, the
        // source regex treats the entire suffix as the optional title group.
        return [$version, null, trim($rest)];
    }

    private function parseDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date->format('Y-m-d');
    }
}
