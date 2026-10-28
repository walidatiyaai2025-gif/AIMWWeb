import { describe, expect, it } from 'vitest';
import { GLOBAL_POSTS_PRIVATE_SELECTED_OPERATION_ID, globalPostsPrivateEndpoint, globalPostsPrivatePayload } from '../global-posts-private-selected-control';

describe('AIMW-SYNC-078D172C71 global posts private parity', () => {
    it('uses only authenticated tenant-scoped route and correct operation marker', () => {
        expect(GLOBAL_POSTS_PRIVATE_SELECTED_OPERATION_ID).toBe('AIMW-SYNC-078D172C71');
        expect(globalPostsPrivateEndpoint('alpha team'))
            .toBe('/api/v1/tenants/alpha%20team/global-posts/private');
        expect(globalPostsPrivateEndpoint('alpha/../other')).toBeNull();
    });

    it('submits unique site/WordPress IDs, never owner identity', () => {
        const result = globalPostsPrivatePayload([{ site_id: 3, wordpress_id: 47 }]);
        expect(result).toEqual({ targets: [{ site_id: 3, wordpress_id: 47 }] });
        expect(result).not.toHaveProperty('tenant_id');
        expect(result).not.toHaveProperty('user_id');
        expect(() => globalPostsPrivatePayload([{ site_id: 0, wordpress_id: 47 }])).toThrow();
        expect(() => globalPostsPrivatePayload([{ site_id: 3, wordpress_id: 47 }, { site_id: 3, wordpress_id: 47 }])).toThrow();
        expect(() => globalPostsPrivatePayload([])).toThrow();
    });

    it('enforces 100-item limit before backend 403/404 tenant/content guards', () => {
        expect(() => globalPostsPrivatePayload(Array.from({ length: 101 }, (_, i) => ({ site_id: 3, wordpress_id: i+1 })))).toThrow();
        expect(globalPostsPrivateEndpoint('cross-tenant/path')).toBeNull();
    });
});
