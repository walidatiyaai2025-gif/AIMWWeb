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

                $header = trim(substr($line, 3));
                $matched = preg_match(
                    '/^v?(?<version>\d+(?:\.\d+){1,3})(?:\s*[-–—]\s*(?<date>\d{4}-\d{2}-\d{2}))?(?:\s*[-–—:]\s*(?<title>.+))?$/u',
                    $header,
                    $matches,
                );

                if ($matched === 1) {
                    $version = $matches['version'];
                    $date = $this->parseDate($matches['date'] ?? '');
                    $title = isset($matches['title']) && trim($matches['title']) !== ''
                        ? trim($matches['title'])
                        : null;
                } else {
                    $version = ltrim($header, 'vV');
                    $date = null;
                    $title = null;
                }

                continue;
            }

            if ($version !== null && (str_starts_with($line, '- ') || str_starts_with($line, '* '))) {
                $changes[] = trim(substr($line, 2));
            }
        }

        $flush();

        return $releases;
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
