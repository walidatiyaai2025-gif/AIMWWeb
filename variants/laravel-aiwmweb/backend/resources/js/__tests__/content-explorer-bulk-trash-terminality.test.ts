import { describe, expect, it } from 'vitest';
import {
    CONTENT_EXPLORER_BULK_TRASH_OPERATION_ID,
    buildBulkTrashPayload,
    contentExplorerBulkTrashEndpoint,
} from '../content-explorer-bulk-trash-control';

describe('ContentExplorer BulkTrashAsync terminality', () => {
    it('binds the canonical operation to the tenant/site bulk trash endpoint', () => {
        expect(CONTENT_EXPLORER_BULK_TRASH_OPERATION_ID).toBe('AIMW-BILL-452B93663B');
        expect(contentExplorerBulkTrashEndpoint('alpha team', 12))
            .toBe('/api/v1/tenants/alpha%20team/sites/12/content/bulk/trash');
    });

    it('fails closed for invalid tenant and site identifiers', () => {
        expect(contentExplorerBulkTrashEndpoint('', 12)).toBeNull();
        expect(contentExplorerBulkTrashEndpoint('alpha/beta', 12)).toBeNull();
        expect(contentExplorerBulkTrashEndpoint('alpha', 0)).toBeNull();
        expect(contentExplorerBulkTrashEndpoint('alpha', 'bad')).toBeNull();
    });

    it('submits only the selected content type and WordPress identifiers', () => {
        expect(buildBulkTrashPayload([
            { content_type: 'post', wordpress_id: 101 },
            { content_type: 'page', wordpress_id: 202 },
        ])).toEqual({
            targets: [
                { content_type: 'post', wordpress_id: 101 },
                { content_type: 'page', wordpress_id: 202 },
            ],
        });
    });
});
