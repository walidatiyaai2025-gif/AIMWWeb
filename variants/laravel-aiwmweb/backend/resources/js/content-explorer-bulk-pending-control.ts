export const CONTENT_EXPLORER_BULK_PENDING_OPERATION_ID = 'AIMW-SYNC-DDC8657C2F';

export function contentExplorerBulkPendingEndpoint(tenantSlug: string, siteId: number): string | null {
    const tenant = tenantSlug.trim();
    if (!tenant || /[\\/]/.test(tenant)) return null;
    if (!Number.isSafeInteger(siteId) || siteId < 1) return null;
    return `/api/v1/tenants/${encodeURIComponent(tenant)}/sites/${siteId}/content/bulk`;
}

export function buildContentExplorerBulkPendingPayload(ids: number[]) {
    const unique = [...new Set(ids)];
    if (unique.length === 0 || unique.length > 500 || unique.some((id) => !Number.isSafeInteger(id) || id < 1)) {
        throw new Error('Bulk pending requires 1–500 positive unique content item IDs.');
    }
    return { ids: unique, action: 'pending' as const, payload: {} };
}
