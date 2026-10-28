import { describe, expect, it } from 'vitest';
import { GLOBAL_PAGES_PRIVATE_SELECTED_OPERATION_ID, globalPagesPrivateEndpoint, globalPagesPrivatePayload } from '../global-pages-private-selected-control';

describe('AIMW-SYNC-C43EFD36B1 global pages private parity', () => {
    it('uses only authenticated tenant-scoped route and correct operation marker', () => {
        expect(GLOBAL_PAGES_PRIVATE_SELECTED_OPERATION_ID).toBe('AIMW-SYNC-C43EFD36B1');
        expect(globalPagesPrivateEndpoint('alpha team'))
            .toBe('/api/v1/tenants/alpha%20team/global-pages/private');
        expect(globalPagesPrivateEndpoint('alpha/../other')).toBeNull();
    });

    it('submits unique site/WordPress IDs, never owner identity', () => {
        const result = globalPagesPrivatePayload([{ site_id: 3, wordpress_id: 47 }]);
        expect(result).toEqual({ targets: [{ site_id: 3, wordpress_id: 47 }] });
        expect(result).not.toHaveProperty('tenant_id');
        expect(result).not.toHaveProperty('user_id');
        expect(() => globalPagesPrivatePayload([{ site_id: 0, wordpress_id: 47 }])).toThrow();
        expect(() => globalPagesPrivatePayload([{ site_id: 3, wordpress_id: 47 }, { site_id: 3, wordpress_id: 47 }])).toThrow();
        expect(() => globalPagesPrivatePayload([])).toThrow();
    });

    it('enforces 100-item limit before backend 403/404 tenant/content guards', () => {
        expect(() => globalPagesPrivatePayload(Array.from({ length: 101 }, (_, i) => ({ site_id: 3, wordpress_id: i+1 })))).toThrow();
        expect(globalPagesPrivateEndpoint('cross-tenant/path')).toBeNull();
    });
});
