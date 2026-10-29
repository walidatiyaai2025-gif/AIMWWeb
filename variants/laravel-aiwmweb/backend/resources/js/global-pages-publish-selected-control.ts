export const GLOBAL_PAGES_PUBLISH_SELECTED_OPERATION_ID = 'AIMW-SYNC-ECAB44A389';

export type GlobalPagePublishTarget = {
    site_id: number;
    wordpress_id: number;
};

export function globalPagesPublishEndpoint(tenantSlug: string): string | null {
    const tenant = tenantSlug.trim();
    if (!tenant || /[\\/]/.test(tenant)) return null;

    return `/api/v1/tenants/${encodeURIComponent(tenant)}/global-pages/publish`;
}

export function buildGlobalPagesPublishPayload(targets: GlobalPagePublishTarget[]) {
    return {
        targets: targets.map((target) => ({
            site_id: Number(target.site_id),
            wordpress_id: Number(target.wordpress_id),
        })),
    };
}
