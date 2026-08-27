<?php

defined('ABSPATH') || exit;

final class AIMW_Connector_Security
{
    public const STATES = [
        'SUPPORTED_ENABLED',
        'SUPPORTED_DISABLED',
        'UNSUPPORTED',
        'TEMPORARILY_UNAVAILABLE',
    ];

    public const CAPABILITIES = [
        'health',
        'content.read',
        'content.update',
        'seo.read',
        'seo.write',
        'audit.local',
        'connector.manage',
        'adapters.read',
        'plugins.read',
        'plugins.manage',
        'themes.read',
        'themes.manage',
        'cache.manage',
        'cron.read',
        'cron.manage',
        'diagnostics.read',
        'backup.read',
        'backup.create',
        'backup.restore',
        'filesystem.read',
        'database.read',
        'database.manage',
    ];

    public const SAFE_DEFAULT_SCOPES = [
        'health',
        'content.read',
        'seo.read',
        'connector.manage',
        'adapters.read',
        'plugins.read',
        'themes.read',
        'cron.read',
        'diagnostics.read',
        'backup.read',
        'filesystem.read',
        'database.read',
    ];

    public const HIGH_RISK_DISABLED_SCOPES = [
        'plugins.manage',
        'themes.manage',
        'cache.manage',
        'cron.manage',
        'backup.create',
        'backup.restore',
        'database.manage',
    ];

    public static function operation_scopes(string $operation, array $arguments = []): array
    {
        return match ($operation) {
            'adapters.list' => ['adapters.read'],
            'plugins.list' => ['plugins.read'],
            'plugin.install', 'plugin.activate', 'plugin.deactivate', 'plugin.update', 'plugin.delete' => ['plugins.manage'],
            'themes.list' => ['themes.read'],
            'theme.install', 'theme.activate', 'theme.update', 'theme.delete' => ['themes.manage'],
            'cache.purge' => ['cache.manage'],
            'cron.list', 'cron.inspect' => ['cron.read'],
            'cron.run_due', 'cron.run' => ['cron.manage'],
            'site.health' => ['diagnostics.read'],
            'backup.list', 'backup.inspect' => ['backup.read'],
            'backup.create' => ['backup.create'],
            'backup.restore' => ['backup.restore'],
            'filesystem.inspect' => ['filesystem.read'],
            'database.health' => ['database.read'],
            'database.optimize' => ['database.manage'],
            default => throw new InvalidArgumentException('Unknown connector operation.'),
        };
    }

    public static function is_mutating_operation(string $operation): bool
    {
        return in_array($operation, [
            'plugin.install', 'plugin.activate', 'plugin.deactivate', 'plugin.update', 'plugin.delete',
            'theme.install', 'theme.activate', 'theme.update', 'theme.delete',
            'cache.purge', 'cron.run_due', 'cron.run', 'backup.create', 'database.optimize',
        ], true);
    }

    public static function normalize_relative_path(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || $path === '.') {
            return '';
        }
        if (str_contains($path, "\0") || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path)) {
            throw new InvalidArgumentException('Unsafe filesystem path.');
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new InvalidArgumentException('Filesystem traversal is not allowed.');
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    public static function assert_slug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || ! preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $slug)) {
            throw new InvalidArgumentException('Invalid WordPress.org slug.');
        }

        return $slug;
    }

    public static function reject_unsafe_database_arguments(array $arguments): void
    {
        foreach (array_keys($arguments) as $key) {
            if (in_array(strtolower((string) $key), ['sql', 'query', 'statement', 'raw_sql'], true)) {
                throw new InvalidArgumentException('Raw SQL operations are not supported.');
            }
        }
    }

    public static function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/secret|token|password|api[_-]?key|signature|authorization|cookie/i', $key)) {
            return '[REDACTED]';
        }
        if (! is_array($value)) {
            return $value;
        }
        $redacted = [];
        foreach ($value as $childKey => $childValue) {
            $redacted[$childKey] = self::redact($childValue, (string) $childKey);
        }

        return $redacted;
    }

    public static function state_hash(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $encoded = function_exists('wp_json_encode') ? wp_json_encode(self::redact($value)) : json_encode(self::redact($value));

        return is_string($encoded) ? hash('sha256', $encoded) : null;
    }
}
