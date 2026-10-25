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
    onRefresh: () => Promise<unknown> | unknown;
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
    const hasTenantPermission = context.permissions.includes('tenant.view') || context.permissions.includes('*');
    const hasDiagnosticsPermission = context.permissions.includes('diagnostics.view') || context.permissions.includes('*');
    const busy = refreshing || localPending;

    if (!activeTenantIsAuthoritative || !hasTenantPermission || !hasDiagnosticsPermission) {
        return null;
    }

    async function refreshAsync() {
        if (pending.current || refreshing) return;
        pending.current = true;
        setLocalPending(true);
        try {
            await onRefresh();
        } catch {
            // The authoritative query owns the visible error state; never fabricate success here.
        } finally {
            pending.current = false;
            setLocalPending(false);
        }
    }

    return (
        <button
            type="button"
            className="btn primary system-health-refresh-control"
            disabled={busy}
            aria-busy={busy ? 'true' : 'false'}
            onClick={() => void refreshAsync()}
            data-canonical-operation={SYSTEM_HEALTH_REFRESH_OPERATION_ID}
        >
            {busy
                ? (locale === 'ar' ? 'جارٍ الفحص...' : 'Checking...')
                : (locale === 'ar' ? 'إعادة الفحص' : 'Run checks')}
        </button>
    );
}
