import React, { useCallback, useEffect, useRef, useState } from 'react';
import { ApiError, apiRequest, type FrontendContext, type WorkspaceRoute } from './core';
import { LoadingState, Pagination, StateBanner, StatePanel } from './components';
import { AppFilterBar } from './app-filter-bar';
import { AppFilterChip } from './app-filter-chip';
import { useLocale } from './i18n';

export const AI_USAGE_LOAD_OPERATION_ID = 'AIMW-BILL-258E431558';

interface AiUsageSummary {
    total_calls: number;
    successful_calls: number;
    success_rate: number;
    input_units: number;
    output_units: number;
    estimated_cost: number;
    actual_cost: number;
}

interface AiUsageSite {
    id: number;
    name: string;
}

interface AiUsageRow {
    id: number;
    site_id: number | null;
    provider: string;
    model: string | null;
    workflow: string | null;
    input_units: number;
    output_units: number;
    estimated_cost: number;
    status: string;
    failure_kind: string | null;
    created_at: string | null;
}

interface AiUsagePayload {
    summary: AiUsageSummary;
    sites: AiUsageSite[];
    recent: AiUsageRow[];
    total: number;
    current_page?: number;
    last_page?: number;
    per_page?: number;
}

function requestUrl(endpoint: string, siteId: string, page: number, pageSize: number): string {
    const url = new URL(endpoint, window.location.origin);
    url.searchParams.set('page', String(page));
    url.searchParams.set('per_page', String(pageSize));
    if (siteId) url.searchParams.set('site', siteId);
    else url.searchParams.delete('site');
    return `${url.pathname}${url.search}`;
}

function errorMessage(error: unknown, locale: 'en' | 'ar'): string {
    if (error instanceof ApiError) return error.message;
    if (error instanceof Error && error.message) return error.message;
    return locale === 'ar' ? 'تعذر تحميل بيانات استخدام الذكاء الاصطناعي.' : 'AI usage data could not be loaded.';
}

export function AiUsageLoadWorkspace({ context, route }: { context: FrontendContext; route: WorkspaceRoute }) {
    const { locale } = useLocale();
    const endpoint = route.apiKey ? context.api[route.apiKey] : undefined;
    const canLoad = context.permissions.includes('tenant.view') && context.permissions.includes('ai.viewUsage');
    const [snapshot, setSnapshot] = useState<AiUsagePayload | null>(null);
    const [selectedSiteId, setSelectedSiteId] = useState('');
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(25);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [loadedAt, setLoadedAt] = useState<Date | null>(null);
    const requestEpoch = useRef(0);

    const load = useCallback(async (siteId: string, requestedPage = 1, requestedSize = 25) => {
        if (!canLoad || !endpoint) return;
        const epoch = ++requestEpoch.current;
        setLoading(true);
        setError('');
        try {
            const payload = await apiRequest<AiUsagePayload>(requestUrl(endpoint, siteId, requestedPage, requestedSize));
            if (epoch !== requestEpoch.current) return;
            setSnapshot(payload);
            setPage(payload.current_page ?? requestedPage);
            setLoadedAt(new Date());
        } catch (reason) {
            if (epoch !== requestEpoch.current) return;
            setError(errorMessage(reason, locale));
        } finally {
            if (epoch === requestEpoch.current) setLoading(false);
        }
    }, [canLoad, endpoint, locale]);

    useEffect(() => {
        void load('', 1, 25);
        return () => { requestEpoch.current += 1; };
    }, [load]);

    if (!canLoad) {
        return (
            <StatePanel tone="danger" title={locale === 'ar' ? 'صلاحية استخدام الذكاء الاصطناعي مطلوبة' : 'AI usage permission required'}>
                {locale === 'ar' ? 'لا يمكن تحميل بيانات الاستخدام لهذا الحساب.' : 'Usage data cannot be loaded for this tenant.'}
            </StatePanel>
        );
    }

    if (!endpoint) {
        return (
            <StatePanel tone="warning" title={locale === 'ar' ? 'تكامل الاستخدام غير متاح' : 'AI usage integration unavailable'}>
                {locale === 'ar' ? 'لم يعلن الخادم عن عقد قراءة استخدام الذكاء الاصطناعي.' : 'The server did not advertise an AI usage read contract.'}
            </StatePanel>
        );
    }

    if (loading && snapshot === null) return <LoadingState />;

    if (error && snapshot === null) {
        return (
            <StatePanel
                tone="danger"
                title={locale === 'ar' ? 'تعذر تحميل سجل الاستخدام' : 'Could not load AI usage'}
                action={(
                    <button
                        type="button"
                        className="btn"
                        data-canonical-operation={AI_USAGE_LOAD_OPERATION_ID}
                        onClick={() => void load(selectedSiteId, page, pageSize)}
                        disabled={loading}
                    >{locale === 'ar' ? 'إعادة المحاولة' : 'Try again'}</button>
                )}
            >
                <p role="alert">{error}</p>
            </StatePanel>
        );
    }

    const rows = snapshot?.recent ?? [];
    const summary = snapshot?.summary;

    return (
        <div className="workspace-stack" data-ai-usage-load-state={loading ? 'loading' : error ? 'stale' : 'ready'}>
            <AppFilterBar
                ariaLabel={locale === 'ar' ? 'فلاتر استخدام الذكاء الاصطناعي' : 'AI usage filters'}
                activeFilterCount={selectedSiteId ? 1 : 0}
                activeFiltersText={locale === 'ar' ? 'فلتر نشط' : 'active filter'}
                noFiltersText={locale === 'ar' ? 'عرض كل المواقع' : 'Showing all sites'}
                appliedFiltersAriaLabel={locale === 'ar' ? 'الفلاتر المطبقة' : 'Applied filters'}
                clearText={locale === 'ar' ? 'مسح الفلاتر' : 'Clear filters'}
                clearAriaLabel={locale === 'ar' ? 'مسح فلتر الموقع' : 'Clear site filter'}
                busy={loading}
                onClearRequested={async () => {
                    setSelectedSiteId('');
                    await load('', 1, pageSize);
                }}
                appliedFilters={selectedSiteId ? (
                    <AppFilterChip
                        label={locale === 'ar' ? 'الموقع' : 'Site'}
                        value={snapshot?.sites.find((site) => String(site.id) === selectedSiteId)?.name ?? selectedSiteId}
                        removeAriaLabel={locale === 'ar' ? 'إزالة فلتر الموقع' : 'Remove site filter'}
                        disabled={loading}
                        onRemoveRequested={async () => {
                            setSelectedSiteId('');
                            await load('', 1, pageSize);
                        }}
                    />
                ) : undefined}
            >
                <label>
                    <span>{locale === 'ar' ? 'تصفية حسب الموقع' : 'Filter by site'}</span>
                    <select
                        aria-label={locale === 'ar' ? 'اختيار موقع لفلترة سجل الاستخدام' : 'Choose site to filter usage history'}
                        value={selectedSiteId}
                        disabled={loading}
                        onChange={(event) => {
                            const next = event.target.value;
                            setSelectedSiteId(next);
                            setPage(1);
                            void load(next, 1, pageSize);
                        }}
                    >
                        <option value="">{locale === 'ar' ? 'كل مواقعي' : 'All my sites'}</option>
                        {(snapshot?.sites ?? []).map((site) => <option key={site.id} value={String(site.id)}>{site.name}</option>)}
                    </select>
                </label>
                <div className="toolbar-actions">
                    <button
                        type="button"
                        className="btn"
                        data-canonical-operation={AI_USAGE_LOAD_OPERATION_ID}
                        onClick={() => void load(selectedSiteId, page, pageSize)}
                        disabled={loading}
                    >↻ {loading ? (locale === 'ar' ? 'جارٍ التحديث…' : 'Refreshing…') : (locale === 'ar' ? 'تحديث' : 'Refresh')}</button>
                </div>
            </AppFilterBar>

            {loading && snapshot ? (
                <StateBanner
                    kind="info"
                    title={locale === 'ar' ? 'جارٍ تحديث البيانات' : 'Refreshing usage data'}
                    description={locale === 'ar' ? 'آخر نتيجة ناجحة ما زالت ظاهرة أثناء القراءة الحالية.' : 'The last successful snapshot remains visible during the current read.'}
                    busy
                />
            ) : null}
            {error && snapshot ? (
                <StateBanner
                    kind="cached"
                    title={locale === 'ar' ? 'تعذر التحديث — آخر بيانات ناجحة ما زالت ظاهرة' : 'Refresh failed — showing last successful data'}
                    description={error}
                    freshnessText={loadedAt ? `${locale === 'ar' ? 'آخر تحديث ناجح' : 'Last successful refresh'}: ${loadedAt.toLocaleString()}` : undefined}
                    onRetry={() => void load(selectedSiteId, page, pageSize)}
                    busy={loading}
                    retryText={locale === 'ar' ? 'إعادة المحاولة' : 'Retry refresh'}
                />
            ) : null}

            {summary ? (
                <section className="metric-grid" aria-label={locale === 'ar' ? 'مؤشرات استخدام الذكاء الاصطناعي' : 'AI usage metrics'}>
                    <article className="metric-card"><small>{locale === 'ar' ? 'إجمالي الطلبات' : 'Total calls'}</small><strong>{summary.total_calls}</strong></article>
                    <article className="metric-card"><small>{locale === 'ar' ? 'معدل النجاح' : 'Success rate'}</small><strong>{Math.round(summary.success_rate * 100)}%</strong></article>
                    <article className="metric-card"><small>{locale === 'ar' ? 'الرموز' : 'Tokens'}</small><strong>{summary.input_units + summary.output_units}</strong></article>
                    <article className="metric-card"><small>{locale === 'ar' ? 'التكلفة المقدرة' : 'Estimated cost'}</small><strong>${summary.estimated_cost.toFixed(4)}</strong></article>
                </section>
            ) : null}

            <section className="panel data-panel">
                <header className="panel-header"><div><span className="workspace-kicker">AI OBSERVABILITY</span><h2>{route.label[locale]}</h2></div><span className="count-badge">{snapshot?.total ?? 0}</span></header>
                {rows.length ? (
                    <div className="table-scroll">
                        <table>
                            <thead><tr><th>{locale === 'ar' ? 'الوقت' : 'Time'}</th><th>{locale === 'ar' ? 'المزود' : 'Provider'}</th><th>{locale === 'ar' ? 'العملية' : 'Operation'}</th><th>{locale === 'ar' ? 'الموقع' : 'Site'}</th><th>Tokens</th><th>{locale === 'ar' ? 'الحالة' : 'State'}</th></tr></thead>
                            <tbody>{rows.map((row) => (
                                <tr key={row.id}>
                                    <td>{row.created_at ?? '—'}</td>
                                    <td>{row.provider}{row.model ? ` / ${row.model}` : ''}</td>
                                    <td>{row.workflow ?? '—'}</td>
                                    <td>{row.site_id ? snapshot?.sites.find((site) => site.id === row.site_id)?.name ?? row.site_id : (locale === 'ar' ? 'بدون موقع' : 'No site')}</td>
                                    <td>{row.input_units + row.output_units}</td>
                                    <td>{row.status}</td>
                                </tr>
                            ))}</tbody>
                        </table>
                    </div>
                ) : <div className="empty-state"><strong>{locale === 'ar' ? 'لا توجد طلبات بعد' : 'No AI calls yet'}</strong><p>{locale === 'ar' ? 'هذه نتيجة حقيقية فارغة لهذا النطاق.' : 'This is the authoritative empty state for this scope.'}</p></div>}
                <Pagination
                    page={snapshot?.current_page ?? page}
                    lastPage={snapshot?.last_page ?? 1}
                    total={snapshot?.total}
                    pageSize={snapshot?.per_page ?? pageSize}
                    returnedCount={rows.length}
                    onPage={(next) => { setPage(next); void load(selectedSiteId, next, pageSize); }}
                    onPageSizeChange={(size) => { setPage(1); setPageSize(size); void load(selectedSiteId, 1, size); }}
                />
            </section>
        </div>
    );
}
