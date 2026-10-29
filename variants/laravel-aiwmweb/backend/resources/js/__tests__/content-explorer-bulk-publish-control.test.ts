import { describe, expect, it } from 'vitest';
import {
    CONTENT_EXPLORER_BULK_PUBLISH_OPERATION_ID,
    contentExplorerBulkPublishEndpoint,
    buildContentExplorerBulkPublishPayload,
} from '../content-explorer-bulk-publish-control';

describe('AIMW-SYNC-EE7D7534DF ContentExplorer BulkPublishAsync', () => {
    it('binds publish action to the current authenticated tenant/site endpoint', () => {
        expect(CONTENT_EXPLORER_BULK_PUBLISH_OPERATION_ID).toBe('AIMW-SYNC-EE7D7534DF');
        expect(contentExplorerBulkPublishEndpoint('alpha', 7))
            .toBe('/api/v1/tenants/alpha/sites/7/content/bulk');
        expect(contentExplorerBulkPublishEndpoint('alpha/../foreign', 7)).toBeNull();
        expect(contentExplorerBulkPublishEndpoint('alpha', 0)).toBeNull();
    });

    it('validates local item IDs and never submits caller-controlled tenant or site fields', () => {
        const payload = buildContentExplorerBulkPublishPayload([7, 9, 7]);
        expect(payload).toEqual({ ids: [7, 9], action: 'publish', payload: {} });
        expect(payload).not.toHaveProperty('tenant_id');
        expect(payload).not.toHaveProperty('site_id');
        expect(() => buildContentExplorerBulkPublishPayload([0])).toThrow();
        expect(() => buildContentExplorerBulkPublishPayload([])).toThrow();
        expect(() => buildContentExplorerBulkPublishPayload([1.5])).toThrow();
        expect(() => buildContentExplorerBulkPublishPayload([Number.MAX_SAFE_INTEGER + 1])).toThrow();
    });

    it('enforces a bounded queue before the server 403 permission check and foreign-tenant 404 boundary', () => {
        expect(buildContentExplorerBulkPublishPayload(Array.from({ length: 500 }, (_, i) => i + 1)).ids).toHaveLength(500);
        expect(() => buildContentExplorerBulkPublishPayload(Array.from({ length: 501 }, (_, i) => i + 1))).toThrow();
        expect(contentExplorerBulkPublishEndpoint('foreign/path', 7)).toBeNull();
    });
});
