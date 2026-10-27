import React, { useState } from 'react';

export const ADMIN_BILLING_SUPPORT_CLEAR_SEARCH_OPERATION_ID = 'AIMW-BILL-B4F030B126';

export type AdminBillingSupportClearSearchControlProps = {
    busy?: boolean;
    locale?: 'en' | 'ar';
    available?: boolean;
    onClearRequested?: () => void | Promise<void>;
};

/**
 * Canonical Billing Support clear-search control.
 *
 * The control owns no tenant, subscription, account, provider, or user identifier.
 * The caller is responsible for clearing local search/selection state and rereading
 * the authoritative server-scoped collection. Missing endpoint wiring fails closed.
 */
export function AdminBillingSupportClearSearchControl({
    busy = false,
    locale = 'en',
    available = false,
    onClearRequested,
}: AdminBillingSupportClearSearchControlProps) {
    const [clearing, setClearing] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);
    const wired = available && typeof onClearRequested === 'function';

    if (!wired) {
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
                ? 'تعذر مسح بحث دعم الفوترة لأن إعادة تحميل البيانات الموثوقة فشلت.'
                : 'Unable to clear billing support search because the authoritative reload failed.');
        } finally {
            setClearing(false);
        }
    };

    return (
        <>
            <button
                type="button"
                className="btn"
                data-canonical-operation={ADMIN_BILLING_SUPPORT_CLEAR_SEARCH_OPERATION_ID}
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
