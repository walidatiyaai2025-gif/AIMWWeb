import React, { useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import {
    ApiError,
    apiRequest,
    capabilityReason,
    resolveCapability,
    tenantUrl,
    workspaceRoutes,
    type ActionContract,
    type FrontendContext,
    type WorkspaceRoute,
} from './core';
import { ActionButton, ActionDialog, DataTable, LoadingState, Pagination, StatePanel, useToast } from './components';
import { commonText, useLocale } from './i18n';
import { prepareActionRequest } from './action-contract';
import { AuthoritativeReconciliationError, mutateThenReconcile } from './reconciliation';

type CollectionEnvelope = {
    data?: Array<Record<string, unknown>>;
    items?: Array<Record<string, unknown>>;
    total?: number;
    current_page?: number;
    page?: number;
    last_page?: number;
    lastPage?: number;
    meta?: { total?: number; current_page?: number; last_page?: number };
};

function normalizeCollection(payload: unknown) {
    if (Array.isArray(payload)) return { rows: payload as Array<Record<string, unknown>>, total: payload.length, page: 1, lastPage: 1 };
    const envelope = (payload ?? {}) as CollectionEnvelope;
    const rows = envelope.data ?? envelope.items ?? [];
    const total = envelope.total ?? envelope.meta?.total ?? rows.length;
    const page = envelope.current_page ?? envelope.page ?? envelope.meta?.current_page ?? 1;
    const lastPage = envelope.last_page ?? envelope.lastPage ?? envelope.meta?.last_page ?? Math.max(1, Math.ceil(total / 20));
    return { rows, total, page, lastPage };
}

function endpointWithQuery(endpoint: string, page: number, search: string) {
    const url = new URL(endpoint, window.location.origin);
    url.searchParams.set('page', String(page));
    if (search.trim()) url.searchParams.set('search', search.trim());
    return `${url.pathname}${url.search}`;
}

function DashboardContent({ context, route }: { context: FrontendContext; route: WorkspaceRoute }) {
    const { locale } = useLocale();
    const state = resolveCapability(context, route);
    const endpoint = route.apiKey ? context.api[route.apiKey] : undefined;
    const query = useQuery({
        queryKey: ['dashboard', context.tenant.slug, endpoint],
        queryFn: () => apiRequest<Record<string, unknown>>(endpoint!),
        enabled: state.state === 'enabled' && Boolean(endpoint),
    });

    if (state.state !== 'enabled') return <Unavailable route={route} context={context} state={state} />;
    if (query.isLoading) return <LoadingState />;
    if (query.error) return <QueryError error={query.error} retry={() => query.refetch()} />;

    const payload = query.data ?? {};
    const metricsCandidate = payload.metrics;
    const metrics = Array.isArray(metricsCandidate)
        ? metricsCandidate.filter((metric): metric is Record<string, unknown> => Boolean(metric && typeof metric === 'object'))
        : [];

    return (
        <div className="workspace-stack">
            <section className="hero-panel">
                <div><span className="workspace-kicker">LIVE TENANT DATA</span><h2>{locale === 'ar' ? 'ملخص التشغيل' : 'Operational summary'}</h2><p>{locale === 'ar' ? 'تُعرض المؤشرات فقط عندما يعيدها خادم Laravel للحساب الحالي.' : 'Metrics render only when the Laravel backend returns them for the active tenant.'}</p></div>
                <button type="button" className="btn" onClick={() => query.refetch()}>{locale === 'ar' ? 'تحديث' : 'Refresh'}</button>
            </section>
            {metrics.length ? (
                <section className="metric-grid" aria-label={locale === 'ar' ? 'مؤشرات الحساب' : 'Tenant metrics'}>
                    {metrics.map((metric, index) => (
                        <article className="metric-card" key={String(metric.key ?? index)}>
                            <small>{String(metric.label ?? metric.key ?? '')}</small>
                            <strong>{String(metric.value ?? '—')}</strong>
                            {metric.detail ? <span>{String(metric.detail)}</span> : null}
                        </article>
                    ))}
                </section>
            ) : (
                <StatePanel title={locale === 'ar' ? 'لا توجد مؤشرات حقيقية بعد' : 'No live metrics returned'}>
                    {locale === 'ar' ? 'لم يرسل عقد لوحة التحكم أي metrics. لن تستبدل الواجهة ذلك بأرقام تجريبية.' : 'The dashboard contract returned no metrics. The frontend will not replace them with demo numbers.'}
                </StatePanel>
            )}
        </div>
    );
}

function QueryError({ error, retry }: { error: unknown; retry: () => void }) {
    const { locale, text } = useLocale();
    const apiError = error instanceof ApiError ? error : null;
    return (
        <StatePanel tone="danger" title={locale === 'ar' ? 'فشل تحميل البيانات' : 'Data could not be loaded'} action={<button type="button" className="btn" onClick={retry}>{text(commonText.retry)}</button>}>
            <p>{apiError?.message ?? text(commonText.apiError)}</p>
            {apiError ? <code>HTTP {apiError.status} · {apiError.code}</code> : null}
        </StatePanel>
    );
}

function Unavailable({ route, context, state = resolveCapability(context, route) }: { route: WorkspaceRoute; context: FrontendContext; state?: ReturnType<typeof resolveCapability> }) {
    const { locale } = useLocale();
    const titleByState: Record<string, { en: string; ar: string }> = {
        permission_denied: { en: 'Permission required', ar: 'الصلاحية مطلوبة' },
        disabled_by_owner: { en: 'Disabled by site owner', ar: 'معطل بواسطة مالك الموقع' },
        connector_unavailable: { en: 'Connector capability unavailable', ar: 'قدرة الموصل غير متاحة' },
        protocol_upgrade_required: { en: 'Connector upgrade required', ar: 'يلزم تحديث الموصل' },
        site_disconnected: { en: 'Site disconnected', ar: 'الموقع غير متصل حاليًا' },
        pending_integration: { en: 'Backend integration pending', ar: 'تكامل الخادم قيد الانتظار' },
    };
    const title = titleByState[state.state]?.[locale] ?? (locale === 'ar' ? 'القدرة غير متاحة' : 'Capability unavailable');
    return (
        <StatePanel tone={state.state === 'permission_denied' ? 'danger' : 'warning'} title={title}>
            <p>{capabilityReason(state, locale)}</p>
            <dl className="contract-details">
                <div><dt>{locale === 'ar' ? 'المساحة' : 'Workspace'}</dt><dd>{route.key}</dd></div>
                {route.permission ? <div><dt>{locale === 'ar' ? 'الصلاحية' : 'Permission'}</dt><dd>{route.permission}</dd></div> : null}
                {route.connectorScope ? <div><dt>{locale === 'ar' ? 'نطاق الموصل' : 'Connector scope'}</dt><dd>{route.connectorScope}</dd></div> : null}
                {route.apiKey ? <div><dt>API</dt><dd>{route.apiKey}</dd></div> : null}
            </dl>
        </StatePanel>
    );
}

function ResourceContent({ context, route }: { context: FrontendContext; route: WorkspaceRoute }) {
    const { locale, text } = useLocale();
    const { notify } = useToast();
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [dialog, setDialog] = useState<{ key: string; contract: ActionContract } | null>(null);
    const state = resolveCapability(context, route);
    const endpoint = route.apiKey ? context.api[route.apiKey] : undefined;

    const query = useQuery({
        queryKey: ['workspace', context.tenant.slug, route.key, endpoint, page, search],
        queryFn: () => apiRequest<unknown>(endpointWithQuery(endpoint!, page, search)),
        enabled: state.state === 'enabled' && Boolean(endpoint),
    });

    const mutation = useMutation({
        mutationFn: async (payload: Record<string, string | number>) => {
            if (!dialog) throw new Error('Action contract is missing.');
            const request = prepareActionRequest(dialog.contract, context, payload);
            return mutateThenReconcile(
                () => apiRequest(request.endpoint, { method: request.method, body: request.body }),
                async () => {
                    const refreshed = await query.refetch();
                    if (refreshed.error) throw refreshed.error;
                },
            );
        },
        onSuccess: () => {
            notify(locale === 'ar' ? 'تم تأكيد العملية وتحديث الحالة من الخادم.' : 'The operation was confirmed and reconciled from the server.', 'success');
            setDialog(null);
        },
        onError: (error) => {
            if (error instanceof AuthoritativeReconciliationError) {
                notify(locale === 'ar' ? 'قبل الخادم العملية، لكن تعذر تحديث الحالة الموثوقة. أعد تحميل الشاشة قبل تكرار العملية.' : error.message, 'error');
                return;
            }
            notify(error instanceof Error ? error.message : (locale === 'ar' ? 'فشلت العملية.' : 'The operation failed.'), 'error');
        },
    });

    if (state.state !== 'enabled') return <Unavailable route={route} context={context} state={state} />;
    if (query.isLoading) return <LoadingState />;
    if (query.error) return <QueryError error={query.error} retry={() => query.refetch()} />;

    const collection = normalizeCollection(query.data);
    const serverErrors = mutation.error instanceof ApiError ? mutation.error.validation : {};

    return (
        <div className="workspace-stack">
            <section className="toolbar-panel" aria-label={locale === 'ar' ? 'أدوات مساحة العمل' : 'Workspace tools'}>
                <form className="search-form" onSubmit={(event) => { event.preventDefault(); setPage(1); setSearch(searchInput); }} role="search">
                    <label className="sr-only" htmlFor={`search-${route.key}`}>{text(commonText.search)}</label>
                    <input id={`search-${route.key}`} value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder={locale === 'ar' ? 'بحث في البيانات الحية…' : 'Search live data…'} />
                    <button type="submit" className="btn">{text(commonText.search)}</button>
                </form>
                <div className="toolbar-actions">
                    <button type="button" className="btn" onClick={() => query.refetch()}>{text(commonText.refresh)}</button>
                    {route.controls?.map((actionKey) => <ActionButton key={actionKey} route={route} actionKey={actionKey} context={context} onAvailable={(contract) => setDialog({ key: actionKey, contract })} />)}
                </div>
            </section>
            <section className="panel data-panel">
                <header className="panel-header"><div><span className="workspace-kicker">LIVE DATA</span><h2>{route.label[locale]}</h2></div><span className="count-badge">{collection.total}</span></header>
                {collection.rows.length ? <DataTable rows={collection.rows} /> : <div className="empty-state"><strong>{text(commonText.empty)}</strong><p>{locale === 'ar' ? 'لا يتم إنشاء صفوف تجريبية عندما يعيد الخادم نتيجة فارغة.' : 'No sample rows are synthesized when the server returns an empty result.'}</p></div>}
                <Pagination page={collection.page} lastPage={collection.lastPage} onPage={setPage} />
            </section>
            <ActionDialog
                open={Boolean(dialog)}
                actionKey={dialog?.key ?? ''}
                contract={dialog?.contract ?? null}
                onClose={() => !mutation.isPending && setDialog(null)}
                onSubmit={(values) => mutation.mutate(values)}
                busy={mutation.isPending}
                serverErrors={serverErrors}
            />
        </div>
    );
}

function WorkspaceHub({ context, route }: { context: FrontendContext; route: WorkspaceRoute }) {
    const { locale } = useLocale();
    const tenantSlug = context.tenant.slug;
    const related = useMemo(() => {
        if (route.key === 'welcome' || route.key === 'system-overview' || route.key === 'workspace') return ['overview', 'content', 'seo', 'ai', 'operations', 'reports', 'system'];
        if (route.key === 'content-hub') return ['content'];
        if (route.key === 'operations') return ['operations'];
        return [route.group];
    }, [route]);

    return (
        <div className="workspace-stack">
            <section className="hero-panel"><div><span className="workspace-kicker">WORKSPACE</span><h2>{route.label[locale]}</h2><p>{route.description[locale]}</p></div><span className="tenant-badge">{context.tenant.name}</span></section>
            <section className="workspace-card-grid">
                {workspaceRoutes.filter((candidate) => !candidate.hidden && related.includes(candidate.group) && candidate.key !== route.key).map((candidate) => {
                    const state = resolveCapability(context, candidate);
                    return (
                        <Link className="workspace-card" key={candidate.key} to={tenantUrl(tenantSlug, candidate.path)}>
                            <span className="workspace-card-icon" aria-hidden="true">{candidate.icon}</span>
                            <div><strong>{candidate.label[locale]}</strong><p>{candidate.description[locale]}</p><small className={`capability-pill capability-${state.state}`}>{state.state.replaceAll('_', ' ')}</small></div>
                        </Link>
                    );
                })}
            </section>
        </div>
    );
}

export function WorkspacePage({ context, route }: { context: FrontendContext; route: WorkspaceRoute }) {
    if (route.kind === 'dashboard') return <DashboardContent context={context} route={route} />;
    if (route.kind === 'workspace') return <WorkspaceHub context={context} route={route} />;
    return <ResourceContent context={context} route={route} />;
}

export function NotFoundPage({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    return (
        <StatePanel tone="warning" title={locale === 'ar' ? 'الصفحة غير موجودة' : 'Page not found'} action={<Link className="btn primary" to={tenantUrl(context.tenant.slug, '/')}>{locale === 'ar' ? 'العودة للرئيسية' : 'Back to dashboard'}</Link>}>
            {locale === 'ar' ? 'تحقق من الرابط أو افتح مساحة عمل من القائمة.' : 'Check the address or open a workspace from navigation.'}
        </StatePanel>
    );
}

export function SiteDetailsRoute({ context, route }: { context: FrontendContext; route: WorkspaceRoute }) {
    const { siteId } = useParams();
    const effectiveRoute = { ...route, apiKey: `sites.detail.${siteId ?? ''}` };
    return <ResourceContent context={context} route={effectiveRoute} />;
}
