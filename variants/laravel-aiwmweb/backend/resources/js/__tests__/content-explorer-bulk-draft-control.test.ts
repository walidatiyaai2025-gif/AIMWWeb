import { describe, expect, it } from 'vitest';
import {
    CONTENT_EXPLORER_BULK_DRAFT_OPERATION_ID,
    contentExplorerBulkDraftEndpoint,
    buildContentExplorerBulkDraftPayload,
} from '../content-explorer-bulk-draft-control';

describe('AIMW-SYNC-F7BCCD650C ContentExplorer BulkDraftAsync', () => {
    it('binds draft action to the authenticated tenant/site queue endpoint', () => {
        expect(CONTENT_EXPLORER_BULK_DRAFT_OPERATION_ID).toBe('AIMW-SYNC-F7BCCD650C');
        expect(contentExplorerBulkDraftEndpoint('alpha', 7))
            .toBe('/api/v1/tenants/alpha/sites/7/content/bulk');
        expect(contentExplorerBulkDraftEndpoint('alpha/../foreign', 7)).toBeNull();
        expect(contentExplorerBulkDraftEndpoint('alpha', 0)).toBeNull();
        expect(contentExplorerBulkDraftEndpoint('', 7)).toBeNull();
    });

    it('sends only verified local item IDs and the draft action', () => {
        const payload = buildContentExplorerBulkDraftPayload([7, 9, 7]);
        expect(payload).toEqual({ ids: [7, 9], action: 'draft', payload: {} });
        expect(payload).not.toHaveProperty('tenant_id');
        expect(payload).not.toHaveProperty('site_id');
        expect(() => buildContentExplorerBulkDraftPayload([0])).toThrow();
        expect(() => buildContentExplorerBulkDraftPayload([])).toThrow();
        expect(() => buildContentExplorerBulkDraftPayload([1.5])).toThrow();
        expect(() => buildContentExplorerBulkDraftPayload([Number.MAX_SAFE_INTEGER + 1])).toThrow();
    });

    it('bounds the queue to 500 selected items without accepting forged tenant URLs', () => {
        expect(buildContentExplorerBulkDraftPayload(Array.from({ length: 500 }, (_, i) => i + 1)).ids).toHaveLength(500);
        expect(() => buildContentExplorerBulkDraftPayload(Array.from({ length: 501 }, (_, i) => i + 1))).toThrow();
        expect(contentExplorerBulkDraftEndpoint('foreign/path', 7)).toBeNull();
    });
});
