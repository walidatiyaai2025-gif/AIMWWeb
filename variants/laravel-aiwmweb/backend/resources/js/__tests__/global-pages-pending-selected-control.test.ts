import { describe, expect, it } from 'vitest';
import { GLOBAL_PAGES_PENDING_SELECTED_OPERATION_ID, globalPagesPendingEndpoint, globalPagesPendingPayload } from '../global-pages-pending-selected-control';

describe('AIMW-SYNC-3E7DCF4C71 global pages pending parity', () => {
    it('uses only authenticated tenant-scoped route and correct operation marker', () => {
        expect(GLOBAL_PAGES_PENDING_SELECTED_OPERATION_ID).toBe('AIMW-SYNC-3E7DCF4C71');
        expect(globalPagesPendingEndpoint('alpha team'))
            .toBe('/api/v1/tenants/alpha%20team/global-pages/pending');
        expect(globalPagesPendingEndpoint('alpha/../other')).toBeNull();
    });

    it('submits unique site/WordPress IDs, never owner identity', () => {
        const result = globalPagesPendingPayload([{ site_id: 3, wordpress_id: 47 }]);
        expect(result).toEqual({ targets: [{ site_id: 3, wordpress_id: 47 }] });
        expect(result).not.toHaveProperty('tenant_id');
        expect(result).not.toHaveProperty('user_id');
        expect(() => globalPagesPendingPayload([{ site_id: 0, wordpress_id: 47 }])).toThrow();
        expect(() => globalPagesPendingPayload([{ site_id: 3, wordpress_id: 47 }, { site_id: 3, wordpress_id: 47 }])).toThrow();
        expect(() => globalPagesPendingPayload([])).toThrow();
    });

    it('enforces 100-item limit before backend 403/404 tenant/content guards', () => {
        expect(() => globalPagesPendingPayload(Array.from({ length: 101 }, (_, i) => ({ site_id: 3, wordpress_id: i+1 })))).toThrow();
        expect(globalPagesPendingEndpoint('cross-tenant/path')).toBeNull();
    });
});
