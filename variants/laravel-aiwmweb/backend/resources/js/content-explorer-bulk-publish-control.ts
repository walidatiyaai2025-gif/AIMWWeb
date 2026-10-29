export const CONTENT_EXPLORER_BULK_PUBLISH_OPERATION_ID = 'AIMW-SYNC-EE7D7534DF';

export function contentExplorerBulkPublishEndpoint(tenantSlug: string, siteId: number): string | null {
    const tenant = tenantSlug.trim();
    if (!tenant || /[\\/]/.test(tenant)) return null;
    if (!Number.isSafeInteger(siteId) || siteId < 1) return null;
    return `/api/v1/tenants/${encodeURIComponent(tenant)}/sites/${siteId}/content/bulk`;
}

export function buildContentExplorerBulkPublishPayload(ids: number[]) {
    const unique = [...new Set(ids)];
    if (unique.length === 0 || unique.length > 500 || unique.some((id) => !Number.isSafeInteger(id) || id < 1)) {
        throw new Error('Bulk publish requires 1–500 positive unique local content item IDs.');
    }
    return { ids: unique, action: 'publish' as const, payload: {} };
}
