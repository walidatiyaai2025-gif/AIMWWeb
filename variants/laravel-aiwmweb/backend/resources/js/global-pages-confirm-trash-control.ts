export const GLOBAL_PAGES_CONFIRM_TRASH_OPERATION_ID = 'AIMW-BILL-C499965699';

export type GlobalPageTrashTarget = {
    site_id: number;
    wordpress_id: number;
};

export function globalPagesEndpoint(tenantSlug: string): string | null {
    const tenant = tenantSlug.trim();
    if (!tenant || /[\\/]/.test(tenant)) return null;

    return `/api/v1/tenants/${encodeURIComponent(tenant)}/global-pages`;
}

export function buildGlobalPagesTrashPayload(targets: GlobalPageTrashTarget[]) {
    return {
        targets: targets.map((target) => ({
            site_id: Number(target.site_id),
            wordpress_id: Number(target.wordpress_id),
        })),
    };
}
