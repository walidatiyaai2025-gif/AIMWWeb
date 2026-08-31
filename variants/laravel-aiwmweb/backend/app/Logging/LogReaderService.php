<?php

namespace App\Logging;

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

final class LogReaderService
{
    /**
     * @return list<array{path: string, name: string, size: int, last_write_utc: string}>
     */
    public function getFiles(): array
    {
        $directory = storage_path('logs');

        if (! File::isDirectory($directory)) {
            return [];
        }

        $files = array_values(array_filter(
            File::files($directory),
            static fn (SplFileInfo $file): bool => in_array(strtolower($file->getExtension()), ['log', 'txt'], true),
        ));

        usort($files, static function (SplFileInfo $left, SplFileInfo $right): int {
            $modifiedComparison = $right->getMTime() <=> $left->getMTime();

            return $modifiedComparison !== 0
                ? $modifiedComparison
                : strcmp($left->getPathname(), $right->getPathname());
        });

        return array_map(static fn (SplFileInfo $file): array => [
            'path' => $file->getPathname(),
            'name' => $file->getFilename(),
            'size' => $file->getSize(),
            'last_write_utc' => gmdate('Y-m-d\TH:i:s\Z', $file->getMTime()),
        ], $files);
    }
}
