import { describe, expect, it, vi } from 'vitest';
import { BACKUPS_RELOAD_OPERATION_ID, runAuthoritativeBackupReload } from '../backup-reload-control';

describe('backup reload control', () => {
    it('binds the exact already-terminal canonical operation id', () => {
        expect(BACKUPS_RELOAD_OPERATION_ID).toBe('AIMW-BILL-07A0F6427B');
    });

    it('fails closed while an authoritative reload is already in flight', async () => {
        const refetch = vi.fn(async () => ({ error: null }));
        const onSuccess = vi.fn();
        const onFailure = vi.fn();

        const outcome = await runAuthoritativeBackupReload({ busy: true, refetch, onSuccess, onFailure });

        expect(outcome).toBe('busy');
        expect(refetch).not.toHaveBeenCalled();
        expect(onSuccess).not.toHaveBeenCalled();
        expect(onFailure).not.toHaveBeenCalled();
    });

    it('reports success only after the authoritative reread completes', async () => {
        const events: string[] = [];
        const refetch = vi.fn(async () => {
            events.push('refetch');
            return { error: null };
        });

        const outcome = await runAuthoritativeBackupReload({
            busy: false,
            refetch,
            onSuccess: () => events.push('success'),
            onFailure: () => events.push('failure'),
        });

        expect(outcome).toBe('succeeded');
        expect(events).toEqual(['refetch', 'success']);
    });

    it('surfaces an authoritative reread failure without false success', async () => {
        const failure = new Error('backup reread failed');
        const events: string[] = [];

        const outcome = await runAuthoritativeBackupReload({
            busy: false,
            refetch: async () => {
                events.push('refetch');
                return { error: failure };
            },
            onSuccess: () => events.push('success'),
            onFailure: (error) => {
                expect(error).toBe(failure);
                events.push('failure');
            },
        });

        expect(outcome).toBe('failed');
        expect(events).toEqual(['refetch', 'failure']);
    });

    it('surfaces a thrown reread failure without false success', async () => {
        const failure = new Error('network unavailable');
        const onSuccess = vi.fn();
        const onFailure = vi.fn();

        const outcome = await runAuthoritativeBackupReload({
            busy: false,
            refetch: async () => { throw failure; },
            onSuccess,
            onFailure,
        });

        expect(outcome).toBe('failed');
        expect(onSuccess).not.toHaveBeenCalled();
        expect(onFailure).toHaveBeenCalledWith(failure);
    });
});
