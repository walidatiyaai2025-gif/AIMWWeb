import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
    GLOBAL_SYNCHRONIZATION_ACCEPT_REMOTE_OPERATION_ID,
    acceptRemoteSyncOutcome,
    globalSynchronizationAcceptRemoteEndpoints,
} from '../global-synchronization-accept-remote-control';

describe('GlobalSynchronizationWorkspace AcceptRemoteAsync terminality', () => {
    it('binds the exact canonical operation and tenant/site endpoints', () => {
        expect(GLOBAL_SYNCHRONIZATION_ACCEPT_REMOTE_OPERATION_ID).toBe('AIMW-BILL-6928C148FF');
        expect(globalSynchronizationAcceptRemoteEndpoints('alpha team', 7)).toEqual({
            accept: '/api/v1/tenants/alpha%20team/sites/7/sync/accept-remote',
            run: expect.any(Function),
        });
        expect(globalSynchronizationAcceptRemoteEndpoints('', 7)).toBeNull();
        expect(globalSynchronizationAcceptRemoteEndpoints('alpha/beta', 7)).toBeNull();
        expect(globalSynchronizationAcceptRemoteEndpoints('alpha', 0)).toBeNull();
    });

    it('treats only completed as visible success', () => {
        expect(acceptRemoteSyncOutcome('queued')).toBe('pending');
        expect(acceptRemoteSyncOutcome('running')).toBe('pending');
        expect(acceptRemoteSyncOutcome('completed')).toBe('success');
        expect(acceptRemoteSyncOutcome('partial')).toBe('failure');
        expect(acceptRemoteSyncOutcome('failed')).toBe('failure');
        expect(acceptRemoteSyncOutcome('cancelled')).toBe('failure');
    });

    it('requires confirmation, a full server-asserted run and authoritative status polling before reload', () => {
        const widget = readFileSync('resources/js/global-synchronization-accept-remote-control.tsx', 'utf8');

        expect(widget).toContain('setConfirmOpen(true)');
        expect(widget).toContain('role="dialog"');
        expect(widget).toContain("'Idempotency-Key': nextIdempotencyKey()");
        expect(widget).toContain("accepted.mode !== 'full'");
        expect(widget).toContain("accepted.trigger !== 'accept-remote'");
        expect(widget).toContain('await apiRequest<SyncRunEnvelope>(runEndpoint)');
        expect(widget).toContain("acceptRemoteSyncOutcome(state) !== 'success'");
        expect(widget).toContain('window.location.reload()');
    });
});
