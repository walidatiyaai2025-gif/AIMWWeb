import { describe, expect, it } from 'vitest';
import {
    CONTENT_EXPLORER_SYNCHRONIZE_OPERATION_ID,
    contentExplorerSynchronizeEndpoints,
    contentExplorerSyncOutcome,
} from '../content-explorer-synchronize-control';

describe('ContentExplorer SynchronizeAsync terminality', () => {
    it('binds the canonical operation to the tenant/site synchronization endpoints', () => {
        expect(CONTENT_EXPLORER_SYNCHRONIZE_OPERATION_ID).toBe('AIMW-BILL-5DC460397B');
        const endpoints = contentExplorerSynchronizeEndpoints('alpha team', 12);
        expect(endpoints?.start).toBe('/api/tenants/alpha%20team/sites/12/sync');
        expect(endpoints?.run(44)).toBe('/api/tenants/alpha%20team/sync-runs/44');
    });

    it('fails closed for invalid tenant, site, and run identifiers', () => {
        expect(contentExplorerSynchronizeEndpoints('', 12)).toBeNull();
        expect(contentExplorerSynchronizeEndpoints('alpha/beta', 12)).toBeNull();
        expect(contentExplorerSynchronizeEndpoints('alpha', 0)).toBeNull();
        expect(contentExplorerSynchronizeEndpoints('alpha', 'bad')).toBeNull();

        const endpoints = contentExplorerSynchronizeEndpoints('alpha', 12);
        expect(endpoints?.run(0)).toBeNull();
        expect(endpoints?.run('bad')).toBeNull();
    });

    it('treats only terminal success states as successful', () => {
        expect(contentExplorerSyncOutcome('queued')).toBe('pending');
        expect(contentExplorerSyncOutcome('running')).toBe('pending');
        expect(contentExplorerSyncOutcome('succeeded')).toBe('success');
        expect(contentExplorerSyncOutcome('completed')).toBe('success');
        expect(contentExplorerSyncOutcome('failed')).toBe('failure');
        expect(contentExplorerSyncOutcome('partial')).toBe('failure');
        expect(contentExplorerSyncOutcome('cancelled')).toBe('failure');
        expect(contentExplorerSyncOutcome('cancel_requested')).toBe('failure');
    });
});
