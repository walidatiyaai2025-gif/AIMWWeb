export const BACKUPS_RELOAD_OPERATION_ID = 'AIMW-BILL-07A0F6427B';

export type BackupReloadOutcome = 'busy' | 'failed' | 'succeeded';

type BackupReloadRefetchResult = {
    error?: unknown;
};

type BackupReloadOptions = {
    busy: boolean;
    refetch: () => Promise<BackupReloadRefetchResult>;
    onSuccess: () => void;
    onFailure: (error: unknown) => void;
};

export async function runAuthoritativeBackupReload({
    busy,
    refetch,
    onSuccess,
    onFailure,
}: BackupReloadOptions): Promise<BackupReloadOutcome> {
    if (busy) return 'busy';

    try {
        const refreshed = await refetch();
        if (refreshed.error != null) {
            onFailure(refreshed.error);
            return 'failed';
        }

        onSuccess();
        return 'succeeded';
    } catch (error) {
        onFailure(error);
        return 'failed';
    }
}
