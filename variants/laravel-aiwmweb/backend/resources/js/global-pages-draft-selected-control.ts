export const GLOBAL_PAGES_DRAFT_SELECTED_OPERATION_ID = 'AIMW-SYNC-3389FB622C';

export type GlobalBulkStatusTarget = { site_id: number; wordpress_id: number };

export function globalPagesDraftEndpoint(tenantSlug: string): string | null {
    const slug = tenantSlug.trim();
    if (!slug || /[\\/]/.test(slug)) return null;
    return `/api/v1/tenants/${encodeURIComponent(slug)}/global-pages/draft`;
}

export function globalPagesDraftPayload(targets: GlobalBulkStatusTarget[]) {
    if (!targets.length || targets.length > 100) throw new Error('Select between 1 and 100 items.');
    const keys = new Set<string>();
    const normalized = targets.map((target) => {
        if (!Number.isSafeInteger(target.site_id) || target.site_id < 1
            || !Number.isSafeInteger(target.wordpress_id) || target.wordpress_id < 1) {
            throw new Error('Invalid site or WordPress content identifier.');
        }
        const key = `${target.site_id}:${target.wordpress_id}`;
        if (keys.has(key)) throw new Error('Duplicate content selection.');
        keys.add(key);
        return { site_id: target.site_id, wordpress_id: target.wordpress_id };
    });
    return { targets: normalized };
}
