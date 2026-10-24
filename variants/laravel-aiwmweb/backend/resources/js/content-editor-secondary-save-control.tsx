import React from 'react';

export const CONTENT_EDITOR_SECONDARY_SAVE_OPERATION_ID = 'AIMW-CONT-289CEEF3BA';

export function ContentEditorSecondarySaveControl({
    saving,
    submittingApproval,
    titleValid,
    locale,
}: {
    saving: boolean;
    submittingApproval: boolean;
    titleValid: boolean;
    locale: 'en' | 'ar';
}) {
    return (
        <button
            type="submit"
            className="btn primary full"
            disabled={saving || submittingApproval || !titleValid}
            data-canonical-operation={CONTENT_EDITOR_SECONDARY_SAVE_OPERATION_ID}
        >
            {saving
                ? (locale === 'ar' ? 'جارٍ الحفظ…' : 'Saving…')
                : (locale === 'ar' ? 'حفظ إلى WordPress' : 'Save to WordPress')}
        </button>
    );
}
