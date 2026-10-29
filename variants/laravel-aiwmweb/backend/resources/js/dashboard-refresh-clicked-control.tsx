import React, { useRef, useState } from 'react';
import { useLocale } from './i18n';
import type { FrontendContext } from './core';

export const DASHBOARD_REFRESH_CLICKED_OPERATION_ID = 'AIMW-SYNC-8C35E9A7A0';

export type DashboardRefreshResult = { data?: Record<string, unknown>; error?: Error | null };

export function DashboardRefreshClickedControl({
    context, endpoint, busy, onRefresh,
}: {
    context: FrontendContext;
    endpoint: string | undefined;
    busy: boolean;
    onRefresh: () => Promise<DashboardRefreshResult>;
}) {
    const { locale } = useLocale();
    const inFlight = useRef(false);
    const [pending, setPending] = useState(false);
    const [outcome, setOutcome] = useState<{ role: 'status' | 'alert'; text: string } | null>(null);
    // The API endpoint must be an exact server-advertised tenant-bound dashboard read.
    const expected = `/api/dashboard?tenant=${encodeURIComponent(context.tenant.slug)}`;
    const allowed = endpoint === expected && context.api.dashboard === expected
        && (context.permissions.includes('*') || context.permissions.includes('execution.view'));

    if (!allowed) return null;

    const refresh = async () => {
        if (busy || inFlight.current) return;
        inFlight.current = true;
        setPending(true);
        setOutcome(null);
        try {
            const refreshed = await onRefresh();
            if (refreshed.error) throw refreshed.error;
            if (!refreshed.data || Array.isArray(refreshed.data) || typeof refreshed.data !== 'object') {
                throw new Error('The dashboard did not return an authoritative payload.');
            }
            // UI metrics are rendered by DashboardContent from this same refreshed query.
            setOutcome({ role: 'status', text: locale === 'ar'
                ? 'تم تحديث لوحة التحكم من البيانات الحالية.'
                : 'Dashboard refreshed from current server data.' });
        } catch (error) {
            setOutcome({ role: 'alert', text: error instanceof Error ? error.message
                : (locale === 'ar' ? 'تعذر تحديث لوحة التحكم.' : 'Dashboard refresh failed.') });
        } finally {
            inFlight.current = false;
            setPending(false);
        }
    };

    return <div className="toolbar-actions" data-dashboard-refresh-control>
        <button type="button" className="btn" data-canonical-operation={DASHBOARD_REFRESH_CLICKED_OPERATION_ID}
            disabled={busy || pending} aria-busy={busy || pending ? 'true' : 'false'}
            onClick={() => { void refresh(); }}>
            {busy || pending ? (locale === 'ar' ? 'جارٍ التحديث…' : 'Refreshing…')
                : (locale === 'ar' ? 'تحديث لوحة التحكم' : 'Refresh dashboard')}
        </button>
        {outcome ? <p role={outcome.role}>{outcome.text}</p> : null}
    </div>;
}
