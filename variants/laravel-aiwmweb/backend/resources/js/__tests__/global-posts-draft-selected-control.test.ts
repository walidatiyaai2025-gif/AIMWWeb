import { describe, expect, it } from 'vitest';
import { GLOBAL_POSTS_DRAFT_SELECTED_OPERATION_ID, globalPostsDraftEndpoint, globalPostsDraftPayload } from '../global-posts-draft-selected-control';

describe('AIMW-SYNC-ED1442005E global posts private parity', () => {
    it('uses only authenticated tenant-scoped route and correct operation marker', () => {
        expect(GLOBAL_POSTS_DRAFT_SELECTED_OPERATION_ID).toBe('AIMW-SYNC-ED1442005E');
        expect(globalPostsDraftEndpoint('alpha team'))
            .toBe('/api/v1/tenants/alpha%20team/global-posts/draft');
        expect(globalPostsDraftEndpoint('alpha/../other')).toBeNull();
    });

    it('submits unique site/WordPress IDs, never owner identity', () => {
        const result = globalPostsDraftPayload([{ site_id: 3, wordpress_id: 47 }]);
        expect(result).toEqual({ targets: [{ site_id: 3, wordpress_id: 47 }] });
        expect(result).not.toHaveProperty('tenant_id');
        expect(result).not.toHaveProperty('user_id');
        expect(() => globalPostsDraftPayload([{ site_id: 0, wordpress_id: 47 }])).toThrow();
        expect(() => globalPostsDraftPayload([{ site_id: 3, wordpress_id: 47 }, { site_id: 3, wordpress_id: 47 }])).toThrow();
        expect(() => globalPostsDraftPayload([])).toThrow();
    });

    it('enforces 100-item limit before backend 403/404 tenant/content guards', () => {
        expect(() => globalPostsDraftPayload(Array.from({ length: 101 }, (_, i) => ({ site_id: 3, wordpress_id: i+1 })))).toThrow();
        expect(globalPostsDraftEndpoint('cross-tenant/path')).toBeNull();
    });
});
