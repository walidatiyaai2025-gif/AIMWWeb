import React, { useRef, useState } from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const DASHBOARD_ERROR_RETRY_OPERATION_ID = 'AIMW-SYNC-B11D963A9E';

/**
 * The dashboard error-state Try again control retries the existing,
 * server-advertised tenant dashboard query; it never synthesizes an API
 * endpoint, changes tenant selection, or reports success without a reread.
 */
export function DashboardErrorRetryControl({
    context,
    endpoint,
    onRetry,
}: {
    context: FrontendContext;
    endpoint: string | undefined;
    onRetry: () => Promise<unknown>;
}) {
    const { locale } = useLocale();
    const [retrying, setRetrying] = useState(false);
    const inFlight = useRef(false);

    // Tenant and endpoint come solely from the hydrated, server-owned context.
    // The backend validates auth, active membership, execution.view and tenant.
    if (!context.tenant.slug
        || !context.tenants.some((tenant) => tenant.slug === context.tenant.slug)
        || !endpoint
        || context.api.dashboard !== endpoint
    ) {
        return null;
    }

    const retry = async () => {
        if (inFlight.current) return;
        inFlight.current = true;
        setRetrying(true);
        try {
            await onRetry();
        } catch {
            // QueryError remains visible on an unsuccessful authoritative reread.
            // Do not show a fabricated success toast or change tenant state.
        } finally {
            inFlight.current = false;
            setRetrying(false);
        }
    };

    const label = locale === 'ar' ? 'حاول مرة أخرى' : 'Try again';
    const busyLabel = locale === 'ar' ? 'جارٍ إعادة المحاولة…' : 'Trying again...';
    return (
        <button
            type="button"
            className="btn"
            data-canonical-operation={DASHBOARD_ERROR_RETRY_OPERATION_ID}
            aria-label={label}
            aria-busy={retrying ? 'true' : 'false'}
            disabled={retrying}
            onClick={() => { void retry(); }}
        >
            <span aria-hidden="true">↻</span>
            <span>{retrying ? busyLabel : label}</span>
        </button>
    );
}
