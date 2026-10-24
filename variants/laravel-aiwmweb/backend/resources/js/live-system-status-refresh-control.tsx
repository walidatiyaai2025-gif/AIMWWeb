import React from 'react';
import { useLocale } from './i18n';

export const LIVE_SYSTEM_STATUS_REFRESH_OPERATION_ID = 'AIMW-SYNC-F0C46A9BD1';

export function LiveSystemStatusRefreshControl({
    busy,
    onRefresh,
}: {
    busy: boolean;
    onRefresh: () => void | Promise<unknown>;
}) {
    const { locale } = useLocale();

    return (
        <button
            type="button"
            className="btn"
            data-canonical-operation={LIVE_SYSTEM_STATUS_REFRESH_OPERATION_ID}
            disabled={busy}
            aria-busy={busy ? 'true' : 'false'}
            onClick={() => {
                if (busy) return;
                void onRefresh();
            }}
        >
            {busy
                ? (locale === 'ar' ? 'جارٍ التحديث' : 'Refreshing')
                : (locale === 'ar' ? 'تحديث' : 'Refresh')}
        </button>
    );
}
