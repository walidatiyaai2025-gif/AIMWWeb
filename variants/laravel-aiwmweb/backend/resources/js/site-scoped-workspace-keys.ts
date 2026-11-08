/**
 * These read-only workspace APIs are advertised by Laravel only after
 * selecting an owned WordPress site. Missing them from a tenant-wide
 * context is not evidence of a broken API contract.
 */
export const siteScopedWorkspaceKeys = new Set([
    'explorer', 'posts', 'pages', 'media', 'comments', 'taxonomy',
    'categories', 'tags', 'wp-users', 'sync', 'seo-audit',
    'site-details', 'site-snapshot', 'site-offline-data',
    'site-connection-center', 'site-connection-operations',
]);
