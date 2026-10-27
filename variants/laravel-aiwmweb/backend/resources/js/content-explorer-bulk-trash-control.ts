export const CONTENT_EXPLORER_BULK_TRASH_OPERATION_ID = 'AIMW-BILL-452B93663B';

export type BulkTrashTarget = {
    content_type: 'post' | 'page';
    wordpress_id: number;
};

export function contentExplorerBulkTrashEndpoint(
    tenantSlug: string,
    siteId: string | number,
): string | null {
    const site = String(siteId).trim();
    if (!tenantSlug.trim() || /[\\/]/.test(tenantSlug)) return null;
    if (!/^[1-9]\d*$/.test(site)) return null;

    return `/api/v1/tenants/${encodeURIComponent(tenantSlug)}/sites/${encodeURIComponent(site)}/content/bulk/trash`;
}

export function buildBulkTrashPayload(targets: BulkTrashTarget[]) {
    const normalized = targets.map((target) => ({
        content_type: target.content_type,
        wordpress_id: Number(target.wordpress_id),
    }));

    return { targets: normalized };
}
