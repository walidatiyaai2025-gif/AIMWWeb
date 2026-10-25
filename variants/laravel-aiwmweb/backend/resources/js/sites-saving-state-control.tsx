import React from 'react';

export const SITES_SAVING_STATE_OPERATION_ID = 'AIMW-CONT-2752ED9351';

export function SitesSavingStateControl({
    busy,
    locale = 'en',
    saveOperationId,
}: {
    busy: boolean;
    locale?: 'en' | 'ar';
    saveOperationId: string;
}) {
    return (
        <button
            type="submit"
            className="btn primary"
            data-canonical-operation={saveOperationId}
            data-canonical-visible-operation={SITES_SAVING_STATE_OPERATION_ID}
            data-save-state={busy ? 'saving' : 'idle'}
            disabled={busy}
            aria-busy={busy ? 'true' : 'false'}
        >
            {busy
                ? (locale === 'ar' ? 'جارٍ الحفظ…' : 'Saving…')
                : (locale === 'ar' ? 'حفظ' : 'Save')}
        </button>
    );
}
