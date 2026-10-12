export const SITE_RELIABILITY_RELOAD_OPERATION_ID = 'AIMW-BILL-8F4FA80ED6';

export type SiteReliabilityReloadOutcome = 'busy' | 'failed' | 'succeeded';

type SiteReliabilityReloadResult = {
    error?: unknown;
};

type SiteReliabilityReloadOptions = {
    busy: boolean;
    refetch: () => Promise<SiteReliabilityReloadResult>;
};

export async function runAuthoritativeSiteReliabilityReload({
    busy,
    refetch,
}: SiteReliabilityReloadOptions): Promise<SiteReliabilityReloadOutcome> {
    if (busy) return 'busy';

    try {
        const refreshed = await refetch();
        return refreshed.error == null ? 'succeeded' : 'failed';
    } catch {
        return 'failed';
    }
}
