import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
    GLOBAL_SYNCHRONIZATION_REQUEST_ACCEPT_REMOTE_OPERATION_ID,
    acceptRemoteSyncOutcome,
    globalSynchronizationAcceptRemoteEndpoints,
} from '../global-synchronization-accept-remote-control';

describe('Issue #952 RequestAcceptRemoteAsync exact operation', () => {
    it('binds its canonical ID only to the confirmation trigger, never to fake success', () => {
        expect(GLOBAL_SYNCHRONIZATION_REQUEST_ACCEPT_REMOTE_OPERATION_ID).toBe('AIMW-SYNC-1B63FBC758');
        const ui = readFileSync('resources/js/global-synchronization-accept-remote-control.tsx', 'utf8');
        expect(ui).toContain('data-canonical-operation={GLOBAL_SYNCHRONIZATION_REQUEST_ACCEPT_REMOTE_OPERATION_ID}');
        expect(ui).toContain('onClick={() => setConfirmOpen(true)}');
        expect(ui).toContain('role="dialog"');
        expect(ui).toContain('onClick={() => mutation.mutate()}');
        expect(ui).toContain('accepted.trigger !== \'accept-remote\'');
        expect(ui).toContain('await apiRequest<SyncRunEnvelope>(runEndpoint)');
    });

    it('keeps untrusted tenant/site identifiers and incomplete external runs fail closed', () => {
        expect(globalSynchronizationAcceptRemoteEndpoints('alpha/beta', 7)).toBeNull();
        expect(globalSynchronizationAcceptRemoteEndpoints('alpha\\\\beta', 7)).toBeNull();
        expect(globalSynchronizationAcceptRemoteEndpoints('alpha', -1)).toBeNull();
        expect(globalSynchronizationAcceptRemoteEndpoints('alpha', 0)).toBeNull();
        expect(acceptRemoteSyncOutcome('queued')).toBe('pending');
        expect(acceptRemoteSyncOutcome('failed')).toBe('failure');
        expect(acceptRemoteSyncOutcome('partial')).toBe('failure');
        expect(acceptRemoteSyncOutcome('completed')).toBe('success');
    });
});
