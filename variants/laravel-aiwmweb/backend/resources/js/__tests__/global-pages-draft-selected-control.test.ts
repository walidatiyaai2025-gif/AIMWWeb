import { describe, expect, it } from 'vitest';
import { GLOBAL_PAGES_DRAFT_SELECTED_OPERATION_ID, globalPagesDraftEndpoint, globalPagesDraftPayload } from '../global-pages-draft-selected-control';

describe('AIMW-SYNC-3389FB622C global pages draft parity', () => {
    it('uses only authenticated tenant-scoped route and correct operation marker', () => {
        expect(GLOBAL_PAGES_DRAFT_SELECTED_OPERATION_ID).toBe('AIMW-SYNC-3389FB622C');
        expect(globalPagesDraftEndpoint('alpha team'))
            .toBe('/api/v1/tenants/alpha%20team/global-pages/draft');
        expect(globalPagesDraftEndpoint('alpha/../other')).toBeNull();
    });

    it('submits unique site/WordPress IDs, never owner identity', () => {
        const result = globalPagesDraftPayload([{ site_id: 3, wordpress_id: 47 }]);
        expect(result).toEqual({ targets: [{ site_id: 3, wordpress_id: 47 }] });
        expect(result).not.toHaveProperty('tenant_id');
        expect(result).not.toHaveProperty('user_id');
        expect(() => globalPagesDraftPayload([{ site_id: 0, wordpress_id: 47 }])).toThrow();
        expect(() => globalPagesDraftPayload([{ site_id: 3, wordpress_id: 47 }, { site_id: 3, wordpress_id: 47 }])).toThrow();
        expect(() => globalPagesDraftPayload([])).toThrow();
    });

    it('enforces 100-item limit before backend 403/404 tenant/content guards', () => {
        expect(() => globalPagesDraftPayload(Array.from({ length: 101 }, (_, i) => ({ site_id: 3, wordpress_id: i+1 })))).toThrow();
        expect(globalPagesDraftEndpoint('cross-tenant/path')).toBeNull();
    });
});
