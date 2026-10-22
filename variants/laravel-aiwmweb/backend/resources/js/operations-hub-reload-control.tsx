import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ApiError, apiRequest, tenantUrl, type FrontendContext } from './core';
import { StatePanel } from './components';
import { useLocale } from './i18n';

export const OPERATIONS_HUB_RELOAD_OPERATION_ID = 'AIMW-BILL-2C2CB8CBAC';

type OperationsHubSite = {
    id: number;
    name: string;
    status: string;
    connection_status: string;
    health_state: string;
    last_verified_at?: string | null;
    last_sync_at?: string | null;
};

type OperationsHubOperation = {
    id: number;
    type: string;
    status: string;
    subject_type?: string | null;
    started_at?: string | null;
    completed_at?: string | null;
    updated_at?: string | null;
};

export type OperationsHubSnapshot = {
    sites: OperationsHubSite[];
    operations: OperationsHubOperation[];
    refreshedAt: string;
};

type OperationsHubEnvelope = {
    data?: {
        sites?: OperationsHubSite[];
        operations?: OperationsHubOperation[];
    };
    meta?: {
        operation_id?: string;
        tenant?: string;
        refreshed_at?: string;
    };
};

export async function loadOperationsHubSnapshot(
    endpoint: string,
    tenantSlug: string,
): Promise<OperationsHubSnapshot> {
    const expectedEndpoint = tenantUrl(tenantSlug, '/admin/operations-hub');
    if (endpoint !== expectedEndpoint) {
        throw new Error('Operations Hub reload endpoint does not belong to the active tenant.');
    }

    const payload = await apiRequest<OperationsHubEnvelope>(endpoint);
    if (payload.meta?.operation_id !== OPERATIONS_HUB_RELOAD_OPERATION_ID) {
        throw new Error('Operations Hub reload response has no trusted canonical operation identity.');
    }
    if (payload.meta?.tenant !== tenantSlug) {
        throw new Error('Operations Hub reload response belongs to a different tenant.');
    }
    if (!payload.data || !Array.isArray(payload.data.sites) || !Array.isArray(payload.data.operations)) {
        throw new Error('Operations Hub reload response is incomplete.');
    }

    return {
        sites: payload.data.sites,
        operations: payload.data.operations,
        refreshedAt: payload.meta.refreshed_at ?? '',
    };
}

export function OperationsHubReloadControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const [snapshot, setSnapshot] = useState<OperationsHubSnapshot | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const inFlight = useRef(false);
    const endpoint = context.api['operations-hub'];
    const authorized = context.permissions.includes('*')
        || (context.permissions.includes('operations.manage') && context.permissions.includes('execution.view'));

    const reload = useCallback(async () => {
        if (inFlight.current || !endpoint || !authorized) return;

        inFlight.current = true;
        setSnapshot(null);
        setError(null);
        setLoading(true);

        try {
            const next = await loadOperationsHubSnapshot(endpoint, context.tenant.slug);
            setSnapshot(next);
        } catch (reason) {
            setSnapshot(null);
            setError(reason instanceof ApiError || reason instanceof Error
                ? reason.message
                : (locale === 'ar' ? 'تعذر تحميل سجل العمليات الحالي.' : 'Current operation history could not be loaded.'));
        } finally {
            inFlight.current = false;
            setLoading(false);
        }
    }, [authorized, context.tenant.slug, endpoint, locale]);

    useEffect(() => {
        void reload();
    }, [reload]);

    const summary = useMemo(() => {
        const operations = snapshot?.operations ?? [];
        return {
            total: operations.length,
            succeeded: operations.filter((item) => item.status.toLowerCase() === 'succeeded').length,
            failed: operations.filter((item) => item.status.toLowerCase() === 'failed').length,
        };
    }, [snapshot]);

    if (!authorized) {
        return (
            <StatePanel tone="danger" title={locale === 'ar' ? 'الصلاحية مطلوبة' : 'Permission required'}>
                {locale === 'ar'
                    ? 'تتطلب إعادة تحميل مركز العمليات صلاحيات إدارة العمليات وعرض التنفيذ.'
                    : 'Operations Hub reload requires operations.manage and execution.view.'}
            </StatePanel>
        );
    }

    if (!endpoint) {
        return (
            <StatePanel tone="warning" title={locale === 'ar' ? 'مصدر البيانات غير متاح' : 'Authoritative source unavailable'}>
                {locale === 'ar'
                    ? 'لم يعلن الخادم نقطة قراءة مركز العمليات لهذا الحساب.'
                    : 'The server did not advertise an Operations Hub read endpoint for this tenant.'}
            </StatePanel>
        );
    }

    if (loading) {
        return (
            <section className="hero-panel" data-testid="operations-hub-loading">
                <div>
                    <span className="workspace-kicker">OPERATIONS &amp; MONITORING</span>
                    <h2>{locale === 'ar' ? 'جارٍ تحميل مركز العمليات…' : 'Loading Operations Hub…'}</h2>
                    <p>{locale === 'ar'
                        ? 'تم إخفاء اللقطة السابقة حتى تكتمل القراءة الحالية من الخادم.'
                        : 'The previous snapshot is hidden until the current server read completes.'}</p>
                </div>
            </section>
        );
    }

    if (error) {
        return (
            <StatePanel
                tone="danger"
                title={locale === 'ar' ? 'تعذر تحميل سجل العمليات' : 'Operation history could not be loaded'}
                action={(
                    <button
                        type="button"
                        className="btn primary"
                        data-canonical-operation={OPERATIONS_HUB_RELOAD_OPERATION_ID}
                        onClick={() => void reload()}
                    >
                        {locale === 'ar' ? 'إعادة المحاولة' : 'Retry'}
                    </button>
                )}
            >
                <p>{error}</p>
                <p>{locale === 'ar'
                    ? 'لا يتم عرض أي لقطة قديمة على أنها الحالة الحالية.'
                    : 'No stale snapshot is presented as current state.'}</p>
            </StatePanel>
        );
    }

    if (!snapshot) {
        return null;
    }

    return (
        <section className="workspace-stack" data-testid="operations-hub-live-snapshot">
            <section className="hero-panel">
                <div>
                    <span className="workspace-kicker">OPERATIONS &amp; MONITORING</span>
                    <h2>{locale === 'ar' ? 'مركز عمليات المواقع' : 'Site Operations Hub'}</h2>
                    <p>{locale === 'ar'
                        ? 'بيانات حالية من خادم Laravel للحساب النشط فقط.'
                        : 'Current Laravel server state for the active tenant only.'}</p>
                </div>
                <button
                    type="button"
                    className="btn"
                    data-canonical-operation={OPERATIONS_HUB_RELOAD_OPERATION_ID}
                    onClick={() => void reload()}
                >
                    {locale === 'ar' ? 'تحديث' : 'Refresh'}
                </button>
            </section>

            <section className="metric-grid" aria-label={locale === 'ar' ? 'مؤشرات العمليات' : 'Operations metrics'}>
                <article className="metric-card"><small>{locale === 'ar' ? 'المواقع' : 'Sites'}</small><strong>{snapshot.sites.length}</strong></article>
                <article className="metric-card"><small>{locale === 'ar' ? 'العمليات المحتفظ بها' : 'Retained operations'}</small><strong>{summary.total}</strong></article>
                <article className="metric-card"><small>{locale === 'ar' ? 'ناجحة' : 'Succeeded'}</small><strong>{summary.succeeded}</strong></article>
                <article className="metric-card"><small>{locale === 'ar' ? 'فاشلة' : 'Failed'}</small><strong>{summary.failed}</strong></article>
            </section>

            <section className="data-panel">
                <div className="panel-heading">
                    <div><span className="workspace-kicker">RECENT</span><h3>{locale === 'ar' ? 'آخر العمليات' : 'Recent operations'}</h3></div>
                    {snapshot.refreshedAt ? <small>{snapshot.refreshedAt}</small> : null}
                </div>
                {snapshot.operations.length === 0 ? (
                    <p>{locale === 'ar' ? 'لا توجد عمليات مسجلة حاليًا.' : 'No retained operations are currently recorded.'}</p>
                ) : (
                    <div className="data-table-wrap">
                        <table className="data-table">
                            <thead><tr><th>{locale === 'ar' ? 'النوع' : 'Type'}</th><th>{locale === 'ar' ? 'الحالة' : 'Status'}</th><th>{locale === 'ar' ? 'آخر تحديث' : 'Updated'}</th></tr></thead>
                            <tbody>
                                {snapshot.operations.slice(0, 8).map((item) => (
                                    <tr key={item.id}>
                                        <td>{item.type || '—'}</td>
                                        <td>{item.status || '—'}</td>
                                        <td>{item.updated_at ?? item.completed_at ?? item.started_at ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </section>
    );
}
