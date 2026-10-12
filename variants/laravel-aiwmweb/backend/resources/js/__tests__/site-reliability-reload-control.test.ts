import { describe, expect, it, vi } from 'vitest';
import {
    runAuthoritativeSiteReliabilityReload,
    SITE_RELIABILITY_RELOAD_OPERATION_ID,
} from '../site-reliability-reload-control';

describe('AIMW-BILL-8F4FA80ED6 Site Reliability ReloadAsync', () => {
    it('does not start a duplicate reload while the authoritative reliability read is in flight', async () => {
        const refetch = vi.fn();

        await expect(runAuthoritativeSiteReliabilityReload({ busy: true, refetch })).resolves.toBe('busy');
        expect(refetch).not.toHaveBeenCalled();
        expect(SITE_RELIABILITY_RELOAD_OPERATION_ID).toBe('AIMW-BILL-8F4FA80ED6');
    });

    it('reports success only after a clean authoritative refetch', async () => {
        const refetch = vi.fn().mockResolvedValue({ data: { data: [{ site_id: 1 }] }, error: null });

        await expect(runAuthoritativeSiteReliabilityReload({ busy: false, refetch })).resolves.toBe('succeeded');
        expect(refetch).toHaveBeenCalledTimes(1);
    });

    it('reports failure when the authoritative refetch returns an error', async () => {
        const refetch = vi.fn().mockResolvedValue({ data: undefined, error: new Error('history unavailable') });

        await expect(runAuthoritativeSiteReliabilityReload({ busy: false, refetch })).resolves.toBe('failed');
        expect(refetch).toHaveBeenCalledTimes(1);
    });

    it('reports failure when the authoritative refetch rejects', async () => {
        const refetch = vi.fn().mockRejectedValue(new Error('network failure'));

        await expect(runAuthoritativeSiteReliabilityReload({ busy: false, refetch })).resolves.toBe('failed');
        expect(refetch).toHaveBeenCalledTimes(1);
    });
});
