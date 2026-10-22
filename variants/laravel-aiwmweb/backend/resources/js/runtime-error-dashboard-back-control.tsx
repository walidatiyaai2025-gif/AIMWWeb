import React from 'react';

export const RUNTIME_ERROR_BACK_TO_DASHBOARD_OPERATION_ID = 'AIMW-PLAT-AF47A254FE';

/**
 * Source-equivalent read-only navigation from Routes.razor.
 *
 * Keep this as a native document anchor to the application root. The browser
 * performs a fresh GET and the Laravel server remains authoritative for
 * authentication, active-tenant resolution, and any redirect that follows.
 */
export function RuntimeErrorDashboardBackControl() {
    return (
        <a
            className="btn"
            href="/"
            data-canonical-operation={RUNTIME_ERROR_BACK_TO_DASHBOARD_OPERATION_ID}
        >Back to dashboard</a>
    );
}
