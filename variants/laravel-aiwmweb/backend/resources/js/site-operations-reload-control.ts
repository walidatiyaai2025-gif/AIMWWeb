export const SITE_OPERATIONS_RELOAD_OPERATION_ID = 'AIMW-BILL-B84663A4C0';

export type SiteOperationsReloadOutcome = 'busy' | 'failed' | 'succeeded';

type SiteOperationsReloadResult = {
    error?: unknown;
};

type SiteOperationsReloadOptions = {
    busy: boolean;
    refetch: () => Promise<SiteOperationsReloadResult>;
};

export async function runAuthoritativeSiteOperationsReload({
    busy,
    refetch,
}: SiteOperationsReloadOptions): Promise<SiteOperationsReloadOutcome> {
    if (busy) return 'busy';

    try {
        const refreshed = await refetch();
        return refreshed.error == null ? 'succeeded' : 'failed';
    } catch {
        return 'failed';
    }
}
