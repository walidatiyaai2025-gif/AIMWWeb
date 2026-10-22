<?php

namespace App\SEO;

final class SeoMetadata
{
    public const WRITABLE_FIELDS = ['title', 'seo_title', 'seo_description', 'slug', 'seo_canonical', 'seo_robots'];

    public static function normalize(array $state): array
    {
        $robots = $state['seo_robots'] ?? [];
        if (is_string($robots)) {
            $robots = preg_split('/\s*,\s*/', strtolower($robots), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $robots = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            is_array($robots) ? $robots : []
        ), static fn (string $value): bool => in_array($value, ['index', 'noindex', 'follow', 'nofollow', 'noarchive', 'nosnippet'], true))));
        sort($robots);

        return [
            'title' => trim((string) ($state['title'] ?? '')),
            'seo_title' => trim((string) ($state['seo_title'] ?? '')),
            'seo_description' => trim((string) ($state['seo_description'] ?? '')),
            'slug' => trim((string) ($state['slug'] ?? '')),
            'seo_canonical' => self::canonical((string) ($state['seo_canonical'] ?? '')),
            'seo_robots' => $robots,
            'seo_provider' => $state['seo_provider'] ?? null,
            'remote_modified_at' => isset($state['remote_modified_at']) ? (string) $state['remote_modified_at'] : ($state['modified_at'] ?? null),
        ];
    }

    public static function hash(array $state): string
    {
        return hash('sha256', json_encode(self::normalize($state), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public static function sanitizeProposed(array $changes): array
    {
        $changes = array_intersect_key($changes, array_flip(self::WRITABLE_FIELDS));
        $result = [];
        foreach ($changes as $field => $value) {
            if ($field === 'seo_robots') {
                $result[$field] = self::normalize(['seo_robots' => $value])['seo_robots'];

                continue;
            }
            $text = trim((string) $value);
            if ($field === 'slug') {
                $text = str($text)->slug()->toString();
            }
            if ($field === 'seo_canonical') {
                $text = self::canonical($text);
            }
            $limit = match ($field) {
                'title', 'seo_title' => 200,
                'seo_description' => 500,
                'slug' => 200,
                'seo_canonical' => 2048,
                default => 1000,
            };
            if (mb_strlen($text) > $limit) {
                throw new \InvalidArgumentException("SEO field {$field} exceeds the safe length limit.");
            }
            $result[$field] = $text;
        }
        if ($result === []) {
            throw new \InvalidArgumentException('No supported SEO changes were supplied.');
        }

        return $result;
    }

    private static function canonical(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('Canonical URL must be an absolute URL.');
        }

        return $value;
    }
}
