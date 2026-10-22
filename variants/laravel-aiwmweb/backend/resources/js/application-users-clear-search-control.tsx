import React, { useState } from 'react';

export const APPLICATION_USERS_CLEAR_SEARCH_OPERATION_ID = 'AIMW-SYNC-F135B261B2';

export type ApplicationUsersClearSearchControlProps = {
    searchValue: string;
    busy?: boolean;
    locale?: 'en' | 'ar';
    authorized?: boolean;
    onClearRequested?: () => void | Promise<void>;
};

/**
 * Canonical ApplicationUsers clear-search control.
 *
 * This control deliberately owns no tenant or endpoint identifier. The caller must
 * derive its endpoint from the active tenant context and perform the authoritative
 * reread. Missing authorization or callback wiring is the fail-closed state.
 */
export function ApplicationUsersClearSearchControl({
    searchValue,
    busy = false,
    locale = 'en',
    authorized = false,
    onClearRequested,
}: ApplicationUsersClearSearchControlProps) {
    const [clearing, setClearing] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);
    const canClear = authorized && searchValue.trim() !== '' && typeof onClearRequested === 'function';

    if (!canClear) {
        return null;
    }

    const clearAsync = async (): Promise<void> => {
        if (busy || clearing || !onClearRequested) {
            return;
        }

        setFailure(null);
        setClearing(true);
        try {
            await onClearRequested();
        } catch {
            setFailure(locale === 'ar'
                ? 'تعذر مسح البحث لأن إعادة تحميل البيانات الموثوقة فشلت.'
                : 'Unable to clear search because the authoritative reload failed.');
        } finally {
            setClearing(false);
        }
    };

    return (
        <>
            <button
                type="button"
                className="btn"
                data-canonical-operation={APPLICATION_USERS_CLEAR_SEARCH_OPERATION_ID}
                disabled={busy || clearing}
                aria-busy={busy || clearing ? 'true' : 'false'}
                onClick={() => void clearAsync()}
            >
                {clearing
                    ? (locale === 'ar' ? 'جارٍ المسح…' : 'Clearing…')
                    : (locale === 'ar' ? 'مسح' : 'Clear')}
            </button>
            {failure ? <span role="alert">{failure}</span> : null}
        </>
    );
}
