import { describe, expect, it } from 'vitest';
import {
    CONTENT_EXPLORER_BULK_PENDING_OPERATION_ID,
    contentExplorerBulkPendingEndpoint,
    buildContentExplorerBulkPendingPayload,
} from '../content-explorer-bulk-pending-control';

describe('AIMW-SYNC-DDC8657C2F ContentExplorer BulkPendingAsync', () => {
    it('binds pending action to the current authenticated tenant/site endpoint', () => {
        expect(CONTENT_EXPLORER_BULK_PENDING_OPERATION_ID).toBe('AIMW-SYNC-DDC8657C2F');
        expect(contentExplorerBulkPendingEndpoint('alpha', 7))
            .toBe('/api/v1/tenants/alpha/sites/7/content/bulk');
        expect(contentExplorerBulkPendingEndpoint('alpha/../foreign', 7)).toBeNull();
        expect(contentExplorerBulkPendingEndpoint('alpha', 0)).toBeNull();
    });

    it('validates local item IDs and never submits caller-controlled tenant or site fields', () => {
        const payload = buildContentExplorerBulkPendingPayload([7, 9, 7]);
        expect(payload).toEqual({ ids: [7, 9], action: 'pending', payload: {} });
        expect(payload).not.toHaveProperty('tenant_id');
        expect(payload).not.toHaveProperty('site_id');
        expect(() => buildContentExplorerBulkPendingPayload([0])).toThrow();
        expect(() => buildContentExplorerBulkPendingPayload([])).toThrow();
        expect(() => buildContentExplorerBulkPendingPayload([1.5])).toThrow();
    });

    it('enforces a bounded queue before the server 403 permission check and foreign-tenant 404 boundary', () => {
        expect(() => buildContentExplorerBulkPendingPayload(Array.from({ length: 501 }, (_, i) => i + 1))).toThrow();
        expect(contentExplorerBulkPendingEndpoint('foreign/path', 7)).toBeNull();
    });
});
