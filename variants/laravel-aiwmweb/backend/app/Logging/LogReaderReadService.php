<?php

namespace App\Logging;

use InvalidArgumentException;
use SplQueue;

final class LogReaderReadService
{
    /**
     * @return list<array{number: int, level: string, text: string}>
     */
    public function read(?string $path, int $take = 500): array
    {
        if ($path === null || trim($path) === '') {
            return [];
        }

        $root = realpath(storage_path('logs'));
        if ($root === false) {
            return [];
        }

        $absolute = $this->absolutePath($path);
        $resolved = realpath($absolute);

        if ($resolved !== false) {
            $candidate = $resolved;
        } else {
            $parent = realpath(dirname($absolute));
            $candidate = $parent === false
                ? $absolute
                : $parent.DIRECTORY_SEPARATOR.basename($absolute);
        }

        if (! $this->isInsideRoot($candidate, $root)) {
            throw new InvalidArgumentException('The requested log file is outside the allowed log directory.');
        }

        if (! is_file($candidate)) {
            return [];
        }

        $limit = max(50, min(5000, $take));
        $lines = new SplQueue;
        $handle = fopen($candidate, 'rb');

        if ($handle === false) {
            return [];
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $lines->enqueue(rtrim($line, "\r\n"));

                if ($lines->count() > $limit) {
                    $lines->dequeue();
                }
            }
        } finally {
            fclose($handle);
        }

        $result = [];
        $number = 1;

        foreach ($lines as $text) {
            $result[] = [
                'number' => $number++,
                'level' => $this->detectLevel($text),
                'text' => $text,
            ];
        }

        return $result;
    }

    private function detectLevel(string $value): string
    {
        if (stripos($value, 'critical') !== false || stripos($value, 'fatal') !== false) {
            return 'Critical';
        }

        if (stripos($value, 'error') !== false || stripos($value, 'exception') !== false || stripos($value, 'fail') !== false) {
            return 'Error';
        }

        if (stripos($value, 'warn') !== false) {
            return 'Warning';
        }

        if (stripos($value, 'debug') !== false || stripos($value, 'trace') !== false) {
            return 'Debug';
        }

        return 'Information';
    }

    private function isInsideRoot(string $candidate, string $root): bool
    {
        $candidate = str_replace('\\', '/', $candidate);
        $root = rtrim(str_replace('\\', '/', $root), '/');

        if (PHP_OS_FAMILY === 'Windows') {
            $candidate = strtolower($candidate);
            $root = strtolower($root);
        }

        return $candidate === $root || str_starts_with($candidate, $root.'/');
    }

    private function absolutePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        if (! preg_match('~^(?:[A-Za-z]:/|/)~', $path)) {
            $path = rtrim(str_replace('\\', '/', (string) getcwd()), '/').'/'.$path;
        }

        $drive = '';
        if (preg_match('~^[A-Za-z]:/~', $path) === 1) {
            $drive = substr($path, 0, 2);
            $path = substr($path, 2);
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        $normalized = implode(DIRECTORY_SEPARATOR, $segments);

        if ($drive !== '') {
            return $drive.DIRECTORY_SEPARATOR.$normalized;
        }

        return DIRECTORY_SEPARATOR.$normalized;
    }
}
