export const GLOBAL_POSTS_CONFIRM_TRASH_OPERATION_ID = 'AIMW-BILL-AD4781C803';

export type GlobalPostTrashTarget = {
    site_id: number;
    wordpress_id: number;
};

export function globalPostsEndpoint(tenantSlug: string): string | null {
    const tenant = tenantSlug.trim();
    if (!tenant || /[\\/]/.test(tenant)) return null;

    return `/api/v1/tenants/${encodeURIComponent(tenant)}/global-posts`;
}

export function buildGlobalPostsTrashPayload(targets: GlobalPostTrashTarget[]) {
    return {
        targets: targets.map((target) => ({
            site_id: Number(target.site_id),
            wordpress_id: Number(target.wordpress_id),
        })),
    };
}
