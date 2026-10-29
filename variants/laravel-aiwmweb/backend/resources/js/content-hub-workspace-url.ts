import { tenantUrl } from './core';

export const CONTENT_HUB_WORKSPACE_URL_OPERATION_ID = 'AIMW-CONT-FE1F15935E';

const SOURCE_WORKSPACE_PATHS = new Set([
    '/module/sync',
    '/module/posts',
    '/module/pages',
    '/module/media',
    '/module/taxonomy',
]);

export function isContentHubWorkspacePath(path: string): boolean {
    return SOURCE_WORKSPACE_PATHS.has(path);
}

export function contentHubWorkspaceUrl(tenantSlug: string, path: string, search: string): string {
    const destination = tenantUrl(tenantSlug, path);
    if (!isContentHubWorkspacePath(path)) return destination;

    const site = new URLSearchParams(search).get('site');
    if (!site || !/^[1-9]\d*$/.test(site)) return destination;

    return `${destination}?site=${encodeURIComponent(site)}`;
}
