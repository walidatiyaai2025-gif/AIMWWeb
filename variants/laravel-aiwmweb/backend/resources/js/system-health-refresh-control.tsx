import React, { useRef, useState } from 'react';
import { useLocale } from './i18n';

export const SYSTEM_HEALTH_REFRESH_OPERATION_ID = 'AIMW-SYNC-0DFEE862FA';

export function SystemHealthRefreshControl({
    busy,
    onRefresh,
}: {
    busy: boolean;
    onRefresh: () => void | Promise<unknown>;
}) {
    const { locale } = useLocale();
    const pending = useRef(false);
    const [activating, setActivating] = useState(false);
    const disabled = busy || activating;

    async function refreshAsync() {
        if (disabled || pending.current) return;

        pending.current = true;
        setActivating(true);
        try {
            await onRefresh();
        } finally {
            pending.current = false;
            setActivating(false);
        }
    }

    return (
        <button
            type="button"
            className="btn primary"
            data-canonical-operation={SYSTEM_HEALTH_REFRESH_OPERATION_ID}
            disabled={disabled}
            aria-busy={disabled ? 'true' : 'false'}
            onClick={() => void refreshAsync()}
        >
            {disabled
                ? (locale === 'ar' ? 'جارٍ الفحص...' : 'Checking...')
                : (locale === 'ar' ? 'إعادة الفحص' : 'Run checks')}
        </button>
    );
}
