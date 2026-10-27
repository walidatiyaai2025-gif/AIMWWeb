import React from 'react';

export const WORDPRESS_USERS_WORKING_STATE_OPERATION_ID = 'AIMW-CONT-346657AD9D';

export function WordPressUsersWorkingStateControl({
    busy,
    locale = 'en',
}: {
    busy: boolean;
    locale?: 'en' | 'ar';
}) {
    return (
        <button
            type="submit"
            className="btn primary"
            data-canonical-operation={WORDPRESS_USERS_WORKING_STATE_OPERATION_ID}
            disabled={busy}
            aria-busy={busy ? 'true' : 'false'}
        >
            {busy
                ? (locale === 'ar' ? 'جارٍ الحفظ…' : 'Saving…')
                : (locale === 'ar' ? 'حفظ' : 'Save')}
        </button>
    );
}
