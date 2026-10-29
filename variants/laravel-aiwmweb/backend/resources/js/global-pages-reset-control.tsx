import React from 'react';
import { DEFAULT_GLOBAL_PAGES_FILTERS, globalPagesFilterUrl } from './global-pages-apply-filters-control';
import { useLocale } from './i18n';

export const GLOBAL_PAGES_RESET_OPERATION_ID = 'AIMW-SYNC-FD27B795ED';

/** Reset is a read-only tenant-scoped GET; never accept caller-provided tenant or site ownership. */
export function globalPagesResetUrl(tenantSlug: string): string | null {
    return globalPagesFilterUrl(tenantSlug, DEFAULT_GLOBAL_PAGES_FILTERS);
}

export function GlobalPagesResetControl({
    busy, available, onReset,
}: {
    busy: boolean;
    available: boolean;
    onReset: () => void;
}) {
    const { locale } = useLocale();
    if (!available) return null;

    return (
        <button
            type="button"
            className="btn"
            data-canonical-operation={GLOBAL_PAGES_RESET_OPERATION_ID}
            disabled={busy}
            aria-busy={busy ? 'true' : 'false'}
            onClick={onReset}
        >
            {busy
                ? (locale === 'ar' ? 'جارٍ إعادة الضبط…' : 'Resetting…')
                : (locale === 'ar' ? 'إعادة ضبط' : 'Reset')}
        </button>
    );
}
