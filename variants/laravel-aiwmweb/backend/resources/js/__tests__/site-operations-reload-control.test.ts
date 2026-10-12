import { describe, expect, it, vi } from 'vitest';
import {
    runAuthoritativeSiteOperationsReload,
    SITE_OPERATIONS_RELOAD_OPERATION_ID,
} from '../site-operations-reload-control';

describe('AIMW-BILL-B84663A4C0 Site Operations ReloadAsync', () => {
    it('does not start a duplicate reload while an authoritative read is already in flight', async () => {
        const refetch = vi.fn();

        await expect(runAuthoritativeSiteOperationsReload({ busy: true, refetch })).resolves.toBe('busy');
        expect(refetch).not.toHaveBeenCalled();
        expect(SITE_OPERATIONS_RELOAD_OPERATION_ID).toBe('AIMW-BILL-B84663A4C0');
    });

    it('reports success only after the authoritative refetch completes without an error', async () => {
        const refetch = vi.fn().mockResolvedValue({ data: { data: [{ id: 7 }] }, error: null });

        await expect(runAuthoritativeSiteOperationsReload({ busy: false, refetch })).resolves.toBe('succeeded');
        expect(refetch).toHaveBeenCalledTimes(1);
    });

    it('reports a failed reload when the authoritative refetch returns an error', async () => {
        const error = new Error('history storage unavailable');
        const refetch = vi.fn().mockResolvedValue({ data: undefined, error });

        await expect(runAuthoritativeSiteOperationsReload({ busy: false, refetch })).resolves.toBe('failed');
        expect(refetch).toHaveBeenCalledTimes(1);
    });

    it('reports a failed reload when the authoritative refetch rejects', async () => {
        const refetch = vi.fn().mockRejectedValue(new Error('network failure'));

        await expect(runAuthoritativeSiteOperationsReload({ busy: false, refetch })).resolves.toBe('failed');
        expect(refetch).toHaveBeenCalledTimes(1);
    });
});
