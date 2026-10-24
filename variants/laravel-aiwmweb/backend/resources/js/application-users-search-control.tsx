import React from 'react';

export const APPLICATION_USERS_SEARCH_OPERATION_ID = 'AIMW-SYNC-26F2BF8EE8';

export type ApplicationUsersSearchControlProps = {
    busy?: boolean;
    locale?: 'en' | 'ar';
    authorized?: boolean;
};

/**
 * Canonical ApplicationUsers SearchClickedAsync control.
 *
 * The form caller owns the tenant-derived endpoint and authoritative reread.
 * This button only exposes the source action when the UsersManage-equivalent
 * permission is present and carries no tenant/resource identifier of its own.
 */
export function ApplicationUsersSearchControl({
    busy = false,
    locale = 'en',
    authorized = false,
}: ApplicationUsersSearchControlProps) {
    if (!authorized) {
        return null;
    }

    return (
        <button
            type="submit"
            className="btn"
            data-canonical-operation={APPLICATION_USERS_SEARCH_OPERATION_ID}
            disabled={busy}
            aria-busy={busy ? 'true' : 'false'}
        >
            {busy
                ? (locale === 'ar' ? 'جارٍ البحث…' : 'Searching…')
                : (locale === 'ar' ? 'بحث' : 'Search')}
        </button>
    );
}
