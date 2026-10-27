export const GLOBAL_POSTS_PUBLISH_SELECTED_OPERATION_ID = 'AIMW-SYNC-33EE696FF7';

export type GlobalPostPublishTarget = {
    site_id: number;
    wordpress_id: number;
};

export function globalPostsPublishEndpoint(tenantSlug: string): string | null {
    const tenant = tenantSlug.trim();
    if (!tenant || /[\\/]/.test(tenant)) return null;

    return `/api/v1/tenants/${encodeURIComponent(tenant)}/global-posts/publish`;
}

export function buildGlobalPostsPublishPayload(targets: GlobalPostPublishTarget[]) {
    return {
        targets: targets.map((target) => ({
            site_id: Number(target.site_id),
            wordpress_id: Number(target.wordpress_id),
        })),
    };
}
