import React, { useRef, useState } from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const SYSTEM_HEALTH_REFRESH_OPERATION_ID = 'AIMW-SYNC-0DFEE862FA';

export function SystemHealthRefreshControl({
    context,
    refreshing = false,
    onRefresh,
}: {
    context: FrontendContext;
    refreshing?: boolean;
    onRefresh: () => Promise<unknown>;
}) {
    const { locale } = useLocale();
    const pending = useRef(false);
    const [localPending, setLocalPending] = useState(false);
    const activeTenantIsAuthoritative = Boolean(
        context.tenant.slug
        && context.tenants.some((tenant) => tenant.slug === context.tenant.slug)
        && Number.isSafeInteger(context.user.id)
        && context.user.id > 0,
    );
    const authorized = context.permissions.includes('*')
        || (context.permissions.includes('tenant.view') && context.permissions.includes('diagnostics.view'));
    const busy = refreshing || localPending;

    if (!activeTenantIsAuthoritative || !authorized) return null;

    async function refreshClickedAsync() {
        if (pending.current || busy) return;

        pending.current = true;
        setLocalPending(true);
        try {
            await onRefresh();
        } catch {
            // The workspace renders the authoritative query failure. Never manufacture success here.
        } finally {
            pending.current = false;
            setLocalPending(false);
        }
    }

    return (
        <button
            type="button"
            className="btn primary"
            disabled={busy}
            onClick={() => void refreshClickedAsync()}
            data-canonical-operation={SYSTEM_HEALTH_REFRESH_OPERATION_ID}
        >
            {busy
                ? (locale === 'ar' ? 'جارٍ الفحص...' : 'Checking...')
                : (locale === 'ar' ? 'إعادة الفحص' : 'Run checks')}
        </button>
    );
}
