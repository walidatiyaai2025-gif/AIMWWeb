import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { ApiError, apiRequest, resolveCapability, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const GLOBAL_SYNCHRONIZATION_REFRESH_STATUS_OPERATION_ID = 'AIMW-SYNC-9504A57CAE';

type ActiveSite = { id: number; name: string };
type ActiveSiteContext = FrontendContext & { active_site?: ActiveSite | null };
type SiteSnapshot = {
    total: number;
    meta: {
        site: { id: number; name: string; connection_status?: string | null };
        last_synchronized_at?: string | null;
        totals?: Record<string, number>;
    };
};
type SyncRun = { id: number; site_id: number; state: string; mode: string; completed_at?: string | null; created_at?: string | null };
type SyncHistory = { data: SyncRun[]; total: number };
export type GlobalSyncStatus = {
    siteName: string;
    connectionStatus: string;
    cachedItems: number;
    lastSynchronizedAt: string | null;
    latestRun: SyncRun | null;
    totalRuns: number;
};

const syncRoute = workspaceRoutes.find((route) => route.key === 'sync');
const explorerRoute = workspaceRoutes.find((route) => route.key === 'explorer');

export function globalSynchronizationRefreshEndpoints(context: FrontendContext) {
    const site = (context as ActiveSiteContext).active_site;
    const slug = context.tenant.slug.trim();
    const authorized = (permission: string) => context.permissions.includes('*') || context.permissions.includes(permission);
    if (!syncRoute || !explorerRoute || !slug || /[\\/]/.test(slug)) return null;
    if (!site || !Number.isSafeInteger(site.id) || site.id <= 0) return null;
    if (!['tenant.view', 'sites.view', 'content.view', 'sync.view'].every(authorized)) return null;
    if (resolveCapability(context, syncRoute).state !== 'enabled') return null;
    if (resolveCapability(context, explorerRoute).state !== 'enabled') return null;
    const prefix = `/api/v1/tenants/${encodeURIComponent(slug)}/sites/${site.id}/`;
    if (context.api.sync !== prefix + 'sync' || context.api.explorer !== prefix + 'explorer') return null;
    return { siteId: site.id, sync: context.api.sync, explorer: context.api.explorer };
}

export function validateGlobalSynchronizationRefresh(siteId: number, snapshot: SiteSnapshot, history: SyncHistory): GlobalSyncStatus {
    if (!snapshot || !snapshot.meta?.site || snapshot.meta.site.id !== siteId) {
        throw new ApiError('The authoritative snapshot does not belong to the selected site.', 409, 'site_identity_mismatch');
    }
    if (!Number.isSafeInteger(snapshot.total) || snapshot.total < 0 || !Array.isArray(history?.data) ||
        !Number.isSafeInteger(history.total) || history.total < 0) {
        throw new ApiError('The authoritative synchronization response is malformed.', 409, 'invalid_sync_status');
    }
    if (history.data.some((run) =>
        !run || run.site_id !== siteId || !Number.isSafeInteger(run.id) || run.id <= 0 || typeof run.state !== 'string')) {
        throw new ApiError('A synchronization run belongs to another site or has invalid state.', 409, 'sync_run_identity_mismatch');
    }
    return {
        siteName: snapshot.meta.site.name,
        connectionStatus: snapshot.meta.site.connection_status ?? 'unknown',
        cachedItems: snapshot.total,
        lastSynchronizedAt: snapshot.meta.last_synchronized_at ?? null,
        latestRun: history.data[0] ?? null,
        totalRuns: history.total,
    };
}

export function GlobalSynchronizationRefreshStatusControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const endpoints = globalSynchronizationRefreshEndpoints(context);
    const query = useQuery({
        queryKey: ['global-sync-authoritative-status', context.tenant.slug, endpoints?.siteId],
        enabled: false,
        retry: false,
        queryFn: async () => {
            if (!endpoints) throw new ApiError('No authorized site is selected.', 403, 'sync_site_unavailable');
            // RefreshStatusAsync only rereads the actual tenant/site cache and persisted sync history.
            // It cannot start a new job or mark a failed WordPress run successful.
            const [snapshot, history] = await Promise.all([
                apiRequest<SiteSnapshot>(endpoints.explorer),
                apiRequest<SyncHistory>(endpoints.sync),
            ]);
            return validateGlobalSynchronizationRefresh(endpoints.siteId, snapshot, history);
        },
    });
    if (!endpoints) return null;
    const data = query.data;

    return (
        <section className="toolbar-panel" data-canonical-operation={GLOBAL_SYNCHRONIZATION_REFRESH_STATUS_OPERATION_ID}
            aria-label={locale === 'ar' ? 'تحديث حالة المزامنة الفعلية' : 'Refresh authoritative sync status'}>
            <div className="toolbar-actions">
                <button type="button" className="btn" disabled={query.isFetching} onClick={() => { void query.refetch(); }}>
                    {query.isFetching ? (locale === 'ar' ? 'جارٍ تحديث الحالة…' : 'Refreshing status…') :
                        (locale === 'ar' ? 'تحديث الحالة' : 'Refresh status')}
                </button>
            </div>
            {query.error ? <p role="alert" className="alert error">
                {query.error instanceof Error ? query.error.message :
                    (locale === 'ar' ? 'فشل تحميل حالة المزامنة.' : 'Synchronization status could not be loaded.')}
            </p> : null}
            {data ? <div className="status-grid" role="status">
                <span>{locale === 'ar' ? 'الموقع' : 'Site'}: {data.siteName}</span>
                <span>{locale === 'ar' ? 'حالة الاتصال' : 'Connection'}: {data.connectionStatus}</span>
                <span>{locale === 'ar' ? 'عناصر محلية' : 'Cached items'}: {data.cachedItems}</span>
                <span>{locale === 'ar' ? 'آخر مزامنة' : 'Last synchronized'}: {data.lastSynchronizedAt ?? '—'}</span>
                <span>{locale === 'ar' ? 'آخر تشغيل' : 'Latest run'}: {data.latestRun?.state ?? '—'}</span>
                <span>{locale === 'ar' ? 'سجل التشغيل' : 'Recorded runs'}: {data.totalRuns}</span>
            </div> : null}
        </section>
    );
}
