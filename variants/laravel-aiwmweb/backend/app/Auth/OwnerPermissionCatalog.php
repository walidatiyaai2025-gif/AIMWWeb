<?php

namespace App\Auth;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

final class OwnerPermissionCatalog
{
    /**
     * React workspace routes are compiled away in production ZIPs. Keep their
     * canonical tenant permissions available to both first install and upgrades.
     * Only a tenant Owner role is granted this catalog; this is NOT a wildcard.
     *
     * @var list<string>
     */
    private const WORKSPACE_PERMISSIONS = [
        'tenant.view', 'tenant.manage', 'sites.view', 'sites.manage',
        'content.view', 'content.edit', 'content.manage',
        'sync.view', 'backups.view', 'backup.manage',
        'connector.manage', 'seo.view', 'seo.manage',
        'approvals.view', 'approvals.manage',
        'execution.view', 'executions.manage', 'operations.manage',
        'ai.use', 'ai.manage', 'ai.configure', 'ai.viewUsage',
        'automation.view', 'automation.manage',
        'notifications.view', 'notifications.manage', 'email.manage',
        'reports.view', 'reports.manage', 'diagnostics.view',
        'settings.manage', 'billing.view', 'billing.manage',
        'users.view', 'users.manage', 'members.manage', 'roles.view',
        'roles.manage', 'sessions.view', 'sessions.manage',
    ];

    /** @return list<string> */
    public function all(): array
    {
        $names = array_fill_keys(self::WORKSPACE_PERMISSIONS, true);

        foreach (config('frontend_actions', []) as $action) {
            $permission = $action['permission'] ?? null;
            if (is_string($permission) && $permission !== '') {
                $names[$permission] = true;
            }
        }

        // Source-controlled route defaults are also part of the catalog.
        foreach (Route::getRoutes() as $route) {
            $raw = $route->defaults['workspace_permissions'] ?? '';
            if (! is_string($raw)) {
                continue;
            }
            foreach (explode(',', $raw) as $value) {
                $name = trim($value);
                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }

        // Preserve the original installer discovery for service-only permissions.
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all("/(?:authorize|hasPermission)\\(\\s*['\"]([^'\"]+)['\"]|permission:([A-Za-z0-9._-]+)/",
                File::get($file->getPathname()), $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $name = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }

        $result = array_keys($names);
        sort($result);

        return $result;
    }
}
