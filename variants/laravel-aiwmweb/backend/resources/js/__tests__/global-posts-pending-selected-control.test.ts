import { describe, expect, it } from 'vitest';
import { GLOBAL_POSTS_PENDING_SELECTED_OPERATION_ID, globalPostsPendingEndpoint, globalPostsPendingPayload } from '../global-posts-pending-selected-control';

describe('AIMW-SYNC-246AD1A2AE global posts private parity', () => {
    it('uses only authenticated tenant-scoped route and correct operation marker', () => {
        expect(GLOBAL_POSTS_PENDING_SELECTED_OPERATION_ID).toBe('AIMW-SYNC-246AD1A2AE');
        expect(globalPostsPendingEndpoint('alpha team'))
            .toBe('/api/v1/tenants/alpha%20team/global-posts/pending');
        expect(globalPostsPendingEndpoint('alpha/../other')).toBeNull();
    });

    it('submits unique site/WordPress IDs, never owner identity', () => {
        const result = globalPostsPendingPayload([{ site_id: 3, wordpress_id: 47 }]);
        expect(result).toEqual({ targets: [{ site_id: 3, wordpress_id: 47 }] });
        expect(result).not.toHaveProperty('tenant_id');
        expect(result).not.toHaveProperty('user_id');
        expect(() => globalPostsPendingPayload([{ site_id: 0, wordpress_id: 47 }])).toThrow();
        expect(() => globalPostsPendingPayload([{ site_id: 3, wordpress_id: 47 }, { site_id: 3, wordpress_id: 47 }])).toThrow();
        expect(() => globalPostsPendingPayload([])).toThrow();
    });

    it('enforces 100-item limit before backend 403/404 tenant/content guards', () => {
        expect(() => globalPostsPendingPayload(Array.from({ length: 101 }, (_, i) => ({ site_id: 3, wordpress_id: i+1 })))).toThrow();
        expect(globalPostsPendingEndpoint('cross-tenant/path')).toBeNull();
    });
});
