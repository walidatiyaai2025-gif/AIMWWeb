import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useLocation, useParams } from 'react-router-dom';
import {
    ApiError,
    apiRequest,
    capabilityReason,
    needsWordPressSiteSelection,
    resolveCapability,
    tenantUrl,
    workspaceRoutes,
    type ActionContract,
    type FrontendContext,
    type WorkspaceRoute,
} from './core';
import { ActionButton, ActionDialog, DataTable, LoadingState, Pagination, StatePanel, useToast } from './components';
import { authoritativeCollectionTotal } from './canonical-pagination-window';
import { commonText, useLocale } from './i18n';
import { CONTENT_HUB_WORKSPACE_URL_OPERATION_ID, contentHubWorkspaceUrl, isContentHubWorkspacePath } from './content-hub-workspace-url';
import { AiCenterGenerateControl } from './ai-center-generate-control';
import { AdminBillingSupportClearSearchControl } from './admin-billing-support-clear-search-control';
import { APP_DATA_GRID_PREVIOUS_PAGE_OPERATION_ID } from './app-data-grid-previous-page-control';
import { AppDataGridSortControl } from './app-data-grid-sort-control';
import { AppDataGridClearFiltersControl } from './app-data-grid-clear-filters-control';
import { AppSearchBox } from './app-search-box';
import { AccountProfileEmailSettingsLinkControl } from './account-profile-email-settings-link-control';
import { BillingHistoryPanel } from './billing-history-panel';
import { ApplicationUsersClearSearchControl } from './application-users-clear-search-control';
import { ApplicationUsersSearchControl } from './application-users-search-control';
import { ApplicationUsersRolesPermissionsLinkControl } from './application-users-roles-permissions-link-control';
import { ApplicationUsersStartCreateControl } from './application-users-start-create-control';
import { runAuthoritativeBackupReload } from './backup-reload-control';
import { prepareActionRequest } from './action-contract';
import { AUTOMATION_PHASE_ACTION_OPERATIONS, AUTOMATION_PHASE_REFRESH_OPERATIONS, SCHEDULE_CANCEL_EDIT_OPERATION_ID } from './automation-phase-controls';
import { AuthoritativeReconciliationError, mutateThenReconcile } from './reconciliation';
import { ContentExplorerWordPressLinkControl } from './content-explorer-wordpress-link-control';
import { ContentExplorerMediaSourceUrlControl } from './content-explorer-media-source-url-control';
import { ContentExplorerPreviewPanel } from './content-explorer-preview-panel';
import { CONTENT_EXPLORER_CONTENT_TAB_RESET_EVENT } from './content-explorer-content-tab-control';
import { ContentExplorerResetFiltersControl } from './content-explorer-reset-filters-control';
import { requestedTaxonomyTypeFromLocation } from './content-explorer-tags-tab-control';
import { ContentExplorerPagination } from './content-explorer-previous-page-control';
import {
    ContentExplorerSelectAllFilteredControl,
    contentExplorerSelectionKey,
    loadAllFilteredExplorerKeys,
} from './content-explorer-select-all-filtered-control';
import { ContentExplorerBackToSitesControl } from './content-explorer-back-to-sites-control';
import { ContentExplorerClearSelectionControl } from './content-explorer-clear-selection-control';
import { CurrentUserLogsControl } from './current-user-logs-control';
import { DashboardErrorRetryControl } from './dashboard-error-retry-control';
import { DashboardRefreshClickedControl } from './dashboard-refresh-clicked-control';
import { DashboardSitesNavigationControl } from './dashboard-sites-navigation-control';
import { DashboardSystemHealthNavigationControl } from './dashboard-system-health-navigation-control';
import { DashboardSitesQuickAccessControl } from './dashboard-sites-quick-access-control';
import { DashboardMediaNavigationControl } from './dashboard-media-navigation-control';
import { DashboardConnectorDownloadControl } from './dashboard-connector-download-control';
import { LiveSystemStatusRefreshControl } from './live-system-status-refresh-control';
import { EMAIL_HISTORY_CLEAR_FILTERS_OPERATION_ID, EmailHistoryFiltersControl, type EmailHistoryFilterState } from './email-history-filters-control';
import { ExecutionCenterRowActions, EXECUTION_PAGE_SIZES, EXECUTION_STATUSES, readExecutionUrlState, writeExecutionUrlState, type ExecutionUrlState } from './execution-center-real-actions';
import { GlobalUsersFullManagementControl } from './global-users-full-management-control';
import { WordPressUsersRefreshControl } from './wordpress-users-refresh-control';
import {
    WORDPRESS_SITE_USERS_SEARCH_OPERATION_ID,
    WORDPRESS_SITE_USER_ROLES,
    isAuthorizedWordPressSiteUsersSearch,
    normalizeWordPressSiteUserRole,
    type WordPressSiteUserRole,
} from './wordpress-site-users-search-control';
import { GlobalUsersRefreshControl, type GlobalUsersRole } from './global-users-refresh-control';
import { MediaDeleteControl } from './media-delete-control';
import { MediaAltSuggestionControl } from './media-alt-suggestion-control';
import { MediaOptimizationEditorControl } from './media-optimization-editor-control';
import { authoritativeGlobalMediaApplyEndpoint, defaultGlobalMediaFilters, globalMediaApplyQuery, GlobalMediaApplyControl } from './global-media-apply-control';
import { globalMediaResetState, GlobalMediaResetControl } from './global-media-reset-control';
import { GlobalMediaSyncLinkControl } from './global-media-sync-link-control';
import { GlobalMediaInterpolatedLinksControl } from './media-interpolated-links-control';
import { MediaBackToSitesControl } from './media-back-to-sites-control';
import { MediaManagerEditingSourceUrlControl } from './media-manager-editing-source-url-control';
import { MediaManagerRowSourceUrlControl } from './media-manager-row-source-url-control';
import { NotificationOpenControl } from './notification-open-control';
import { PagesInterpolatedLinksControl } from './pages-interpolated-links-control';
import { PagesWordPressLinkControl } from './pages-wordpress-link-control';
import { PostsWordPressLinkControl } from './posts-wordpress-link-control';
import { RolesApplicationUsersLinkControl } from './roles-application-users-link-control';
import { RolesStartCreateControl } from './roles-start-create-control';
import { SiteOperationsExportControl } from './site-operations-export-control';
import { OperationsHubSiteOperationsControl } from './operations-hub-site-operations-control';
import { SiteOperationsDetailsLinkControl } from './site-operations-details-link-control';
import { SiteOperationsSitesLinkControl } from './site-operations-sites-link-control';
import {
    filterSiteOperationsRows,
    siteOperationsDefaultFilters,
    SiteOperationsFiltersControl,
    type SiteOperationsFilterState,
} from './site-operations-filters-control';
import { SiteReliabilityConnectionLinkControl } from './site-reliability-connection-link-control';
import { GlobalTaxonomySiteLinksControl } from './global-taxonomy-site-links-control';
import { GlobalTaxonomyResetControl, authorizedGlobalTaxonomyResetEndpoint } from './global-taxonomy-reset-control';
import { defaultGlobalTaxonomyApplyFilters, globalTaxonomyApplyQuery, GlobalTaxonomyApplyControl } from './global-taxonomy-apply-control';
import { SiteReliabilityFiltersControl, siteReliabilityDefaultFilters, type SiteReliabilityFilterState } from './site-reliability-filters-control';
import { SitesSiteUrlLinkControl } from './sites-site-url-link-control';
import { SitesInterpolatedLinksControl } from './sites-interpolated-links-control';
import { SitesSystemHealthLinkControl } from './sites-system-health-link-control';
import { runAuthoritativeSiteOperationsReload, SITE_OPERATIONS_RELOAD_OPERATION_ID } from './site-operations-reload-control';
import { runAuthoritativeSiteReliabilityReload, SITE_RELIABILITY_RELOAD_OPERATION_ID } from './site-reliability-reload-control';
import { readWorkspaceCollectionResponse } from './wordpress-collection-contract';
import { SiteReliabilityOperationHistoryLinkControl } from './site-reliability-operation-history-link-control';

const SITES_RELOAD_OPERATION_ID = 'AIMW-SYNC-A9E956A4DA';
const SITES_SHOW_ALL_OPERATION_ID = 'AIMW-CONT-C178278FCB';
const SITES_SHOW_CONNECTED_OPERATION_ID = 'AIMW-CONT-E346398EAD';
const SITES_SHOW_REVIEW_OPERATION_ID = 'AIMW-CONT-CA17F05024';
export const COMMENTS_SEARCH_OPERATION_ID = 'AIMW-SYNC-4E3C0156FC';
export const COMMENTS_PREVIOUS_PAGE_OPERATION_ID = 'AIMW-SYNC-D5CE5A213B';
export const COMMENTS_REFRESH_CLICK_OPERATION_ID = 'AIMW-SYNC-DBD736FACC';
export const WORDPRESS_USERS_SEARCH_OPERATION_ID = 'AIMW-SYNC-E6888B0B4D';
export const WORDPRESS_USERS_PREVIOUS_OPERATION_ID = 'AIMW-SYNC-E0791EA9E9';
export const AI_CENTER_METADATA_REFRESH_OPERATION_ID = 'AIMW-AI-953A6C0D98';
export const NOTIFICATION_INBOX_RELOAD_OPERATION_ID = 'AIMW-EMAI-12A6FEB2FF';

const COLLECTION_READ_OPERATIONS: Partial<Record<string, { load?: string; previous?: string; refresh?: string }>> = {
    comments: { load: 'AIMW-SYNC-12F15A0A80', previous: 'AIMW-SYNC-CB01197D47', refresh: COMMENTS_REFRESH_CLICK_OPERATION_ID },
    media: { load: 'AIMW-SYNC-4E969573BB', previous: 'AIMW-SYNC-F340B5445A' },
    pages: { load: 'AIMW-SYNC-112E6B9631', previous: 'AIMW-SYNC-EF652932D6' },
    posts: { load: 'AIMW-SYNC-12C023E4CC', previous: 'AIMW-SYNC-0EDB4AB9FC' },
    taxonomy: { load: 'AIMW-SYNC-5CF2AC6243', previous: 'AIMW-SYNC-C8C380E7F8' },
    'wp-users': { previous: 'AIMW-SYNC-B78216241F' },
};

const CONTENT_WORKSPACE_LOAD_OPERATION_ID = 'AIMW-SYNC-CD6F1FB97B';

type AiCenterMetadata = {
    operation_id?: string;
    locale?: string;
    available_prompts?: number;
    recent_usage_count?: number;
    sites?: Array<{ id: number; name: string }>;
    approval?: { id: number; status: string; decided_at?: string | null; updated_at?: string | null } | null;
};

type CollectionEnvelope = {
    data?: Array<Record<string, unknown>>;
    items?: Array<Record<string, unknown>>;
    total?: number;
    pagination_authoritative?: boolean;
    current_page?: number;
    page?: number;
    last_page?: number;
    lastPage?: number;
    per_page?: number;
    meta?: ({ total?: number; current_page?: number; last_page?: number; per_page?: number } & AiCenterMetadata);
};

function normalizeCollection(payload: unknown) {
    if (Array.isArray(payload)) return { rows: payload as Array<Record<string, unknown>>, total: payload.length, authoritativeTotal: undefined as number | undefined, page: 1, lastPage: 1 };
    const envelope = (payload ?? {}) as CollectionEnvelope;
    const rows = Array.isArray(envelope.data) ? envelope.data : Array.isArray(envelope.items) ? envelope.items : [];
    const reportedTotal = envelope.total ?? envelope.meta?.total;
    const authoritativeTotal = authoritativeCollectionTotal(reportedTotal, envelope.pagination_authoritative);
    const total = authoritativeTotal ?? rows.length;
    const reportedPage = envelope.current_page ?? envelope.page ?? envelope.meta?.current_page;
    const page = Number.isSafeInteger(reportedPage) && reportedPage! > 0 ? reportedPage! : 1;
    const perPage = envelope.per_page ?? envelope.meta?.per_page;
    const reportedLastPage = envelope.last_page ?? envelope.lastPage ?? envelope.meta?.last_page;
    const calculatedLast = authoritativeTotal !== undefined && Number.isSafeInteger(perPage) && perPage! > 0
        ? Math.max(1, Math.ceil(authoritativeTotal / perPage!)) : 1;
    // No fabricated "20 rows/page" fallback: arrays and truncated reads do not
    // authorize navigating to pages that the API has not declared.
    const lastPage = Number.isSafeInteger(reportedLastPage) && reportedLastPage! > 0 ? reportedLastPage! : calculatedLast;
    return { rows, total, authoritativeTotal, page, lastPage };
}

function isSiteConnectedRow(row: Record<string, unknown>): boolean {
    const normalized = String(row.connection_status ?? 'unknown').trim().toLowerCase() || 'unknown';
    return normalized === 'connected';
}

function isSiteReviewRow(row: Record<string, unknown>): boolean {
    const normalized = String(row.connection_status ?? 'unknown').trim().toLowerCase() || 'unknown';
    return normalized !== 'connected' && normalized !== 'unknown';
}

function endpointWithQuery(endpoint: string, page: number, search: string, filters: Record<string, string> = {}) {
    const url = new URL(endpoint, window.location.origin);
    url.searchParams.set('page', String(page));
    if (search.trim()) url.searchParams.set('search', search.trim());
    for (const [key, value] of Object.entries(filters)) {
        url.searchParams.set(key, value);
    }
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
    if (query.error) return <QueryError
        error={query.error}
        retry={() => { void query.refetch(); }}
        retryAction={
            <DashboardErrorRetryControl
                context={context}
                endpoint={endpoint}
                onRetry={() => query.refetch()}
            />
        }
    />;

    const payload = query.data ?? {};
    const metricsCandidate = payload.metrics;
    const explicitMetrics = Array.isArray(metricsCandidate)
        ? metricsCandidate.filter((metric): metric is Record<string, unknown> => Boolean(metric && typeof metric === 'object'))
        : [];

    const sitesCandidate = payload.sites;
    const sites = sitesCandidate && typeof sitesCandidate === 'object' && !Array.isArray(sitesCandidate)
        ? sitesCandidate as Record<string, unknown>
        : null;
    const hasCanonicalDashboardPayload = Boolean(sites)
        || ['posts', 'pages', 'media', 'activeJobs', 'failedJobs', 'healthScore', 'lastSynchronizationAtUtc']
            .some((key) => Object.prototype.hasOwnProperty.call(payload, key));

    const metricNumber = (value: unknown): number | string => typeof value === 'number' ? value : '—';
    const posts = typeof payload.posts === 'number' ? payload.posts : null;
    const pages = typeof payload.pages === 'number' ? payload.pages : null;
    const cachedContent = posts !== null && pages !== null ? posts + pages : '—';
    const connectedSites = typeof sites?.connectedSites === 'number' ? sites.connectedSites : null;
    const failedJobs = typeof payload.failedJobs === 'number' ? payload.failedJobs : null;
    const lastSync = typeof payload.lastSynchronizationAtUtc === 'string' && payload.lastSynchronizationAtUtc.trim() !== ''
        ? payload.lastSynchronizationAtUtc
        : '—';

    const canonicalMetrics: Array<Record<string, unknown>> = hasCanonicalDashboardPayload ? [
        {
            key: 'sites',
            label: locale === 'ar' ? 'المواقع' : 'Sites',
            value: metricNumber(sites?.totalSites),
            detail: connectedSites !== null ? `${connectedSites} ${locale === 'ar' ? 'متصل' : 'connected'}` : undefined,
        },
        {
            key: 'content',
            label: locale === 'ar' ? 'المحتوى المحلي' : 'Cached content',
            value: cachedContent,
            detail: posts !== null && pages !== null
                ? `${posts} ${locale === 'ar' ? 'مقال' : 'posts'} · ${pages} ${locale === 'ar' ? 'صفحة' : 'pages'}`
                : undefined,
        },
        {
            key: 'media',
            label: locale === 'ar' ? 'الوسائط' : 'Media',
            value: metricNumber(payload.media),
        },
        {
            key: 'active_jobs',
            label: locale === 'ar' ? 'مهام نشطة' : 'Active jobs',
            value: metricNumber(payload.activeJobs),
            detail: failedJobs !== null ? `${failedJobs} ${locale === 'ar' ? 'فاشلة' : 'failed'}` : undefined,
        },
        ...(typeof payload.healthScore === 'number' ? [{
            key: 'health',
            label: locale === 'ar' ? 'صحة المنصة' : 'Platform health',
            value: `${payload.healthScore}%`,
        }] : []),
        {
            key: 'last_sync',
            label: locale === 'ar' ? 'آخر مزامنة' : 'Last sync',
            value: lastSync,
        },
    ] : [];

    const metrics = explicitMetrics.length ? explicitMetrics : canonicalMetrics;

    return (
        <div className="workspace-stack dashboard-overview">
            <section className="hero-panel dashboard-summary">
                <div className="dashboard-summary-copy"><span className="dashboard-summary-mark" aria-hidden="true">▱</span><span className="workspace-kicker">LIVE TENANT DATA</span><h2>{locale === 'ar' ? 'ملخص التشغيل' : 'Operational summary'}</h2><p>{locale === 'ar' ? 'تُعرض المؤشرات فقط عندما يعيدها خادم Laravel للحساب الحالي.' : 'Metrics render only when the Laravel backend returns them for the active tenant.'}</p></div>
                <div className="dashboard-summary-actions">
                    <DashboardRefreshClickedControl context={context} endpoint={endpoint} busy={query.isFetching} onRefresh={() => query.refetch()} />
                <LiveSystemStatusRefreshControl busy={query.isFetching} onClick={() => query.refetch()} />
                <DashboardSitesNavigationControl context={context} />
                <DashboardSystemHealthNavigationControl context={context} />
                <DashboardSitesQuickAccessControl context={context} />
                <DashboardMediaNavigationControl context={context} />
                <DashboardConnectorDownloadControl />
                </div>
            </section>
            {metrics.length ? (
                <section className="metric-grid" aria-label={locale === 'ar' ? 'مؤشرات الحساب' : 'Tenant metrics'}>
                    {metrics.map((metric, index) => (
                        <article className={`metric-card metric-${['sites', 'content', 'media', 'active_jobs', 'health', 'last_sync'].includes(String(metric.key)) ? String(metric.key) : 'other'}`} key={String(metric.key ?? index)}>
                            <span className="metric-card-icon" aria-hidden="true">{({ sites: '◎', content: '▤', media: '▧', active_jobs: '▣', health: '♢', last_sync: '↻' } as Record<string, string>)[String(metric.key)] ?? '◈'}</span>
                            <div className="metric-card-copy">
                                <small>{String(metric.label ?? metric.key ?? '')}</small>
                                <strong>{String(metric.value ?? '—')}</strong>
                                {metric.detail ? <span>{String(metric.detail)}</span> : null}
                            </div>
                        </article>
                    ))}
                </section>
            ) : (
                <StatePanel title={locale === 'ar' ? 'لا توجد مؤشرات حقيقية بعد' : 'No live metrics returned'}>
                    {locale === 'ar' ? 'لم يرسل عقد لوحة التحكم بيانات قابلة للعرض. لن تستبدل الواجهة ذلك بأرقام تجريبية.' : 'The dashboard contract returned no renderable metrics. The frontend will not replace them with demo numbers.'}
                </StatePanel>
            )}
        </div>
    );
}

function QueryError({ error, retry, retryAction }: { error: unknown; retry: () => void; retryAction?: React.ReactNode }) {
    const { locale, text } = useLocale();
    const apiError = error instanceof ApiError ? error : null;
    const title = locale === 'ar' ? 'فشل تحميل البيانات' : 'Data could not be loaded';
    const details = (
        <>
            <p>{apiError?.message ?? text(commonText.apiError)}</p>
            {apiError ? <code>HTTP {apiError.status} · {apiError.code}</code> : null}
        </>
    );

    // Only the dashboard error state owns its canonical Retry operation. Keep
    // shared QueryError's original StatePanel retry contract for all siblings.
    if (retryAction) {
        return <StatePanel tone="danger" title={title} action={retryAction}>{details}</StatePanel>;
    }

    return (
        <StatePanel
            tone="danger"
            title={title}
            onRetry={retry}
            retryText={text(commonText.retry)}
        >
            {details}
        </StatePanel>
    );
}

function Unavailable({ route, context, state = resolveCapability(context, route) }: { route: WorkspaceRoute; context: FrontendContext; state?: ReturnType<typeof resolveCapability> }) {
    const { locale } = useLocale();
    const selectionRequired = state.state === 'pending_integration' && needsWordPressSiteSelection(context, route);
    const mayBrowseSites = context.permissions.includes('sites.view') || context.permissions.includes('*');
    const titleByState: Record<string, { en: string; ar: string }> = {
        permission_denied: { en: 'Permission required', ar: 'الصلاحية مطلوبة' },
        disabled_by_owner: { en: 'Disabled by site owner', ar: 'معطل بواسطة مالك الموقع' },
        connector_unavailable: { en: 'Connector capability unavailable', ar: 'قدرة الموصل غير متاحة' },
        protocol_upgrade_required: { en: 'Connector upgrade required', ar: 'يلزم تحديث الموصل' },
        site_disconnected: { en: 'Site disconnected', ar: 'الموقع غير متصل' },
        pending_integration: { en: 'Backend integration pending', ar: 'تكامل الخادم قيد الانتظار' },
    };
    const title = selectionRequired
        ? (locale === 'ar' ? 'اختر موقع WordPress أولًا' : 'Select a WordPress site first')
        : titleByState[state.state]?.[locale] ?? (locale === 'ar' ? 'القدرة غير متاحة' : 'Capability unavailable');
    return (
        <StatePanel tone={state.state === 'permission_denied' ? 'danger' : 'warning'} title={title}>
            <p>{selectionRequired
                ? (locale === 'ar'
                    ? 'لم يتم اختيار موقع تابع لهذا الحساب بعد. افتح صفحة المواقع واختر الموقع المطلوب لعرض بياناته الفعلية. لن يُنفَّذ أي تعديل على WordPress.'
                    : 'No tenant-owned site is selected. Open Sites and select the site to view its real stored data. No WordPress changes will be made.')
                : capabilityReason(state, locale)}</p>
            {selectionRequired && mayBrowseSites ? (
                <Link className="btn primary" to={tenantUrl(context.tenant.slug, '/sites')} data-site-selection-action="true">
                    {locale === 'ar' ? 'اختيار موقع من المواقع' : 'Choose a site'}
                </Link>
            ) : null}
            {selectionRequired && !mayBrowseSites ? (
                <p>{locale === 'ar' ? 'تحتاج صلاحية عرض المواقع لاختيار الموقع.' : 'You need Sites view permission to select a site.'}</p>
            ) : null}
            <dl className="contract-details">
                <div><dt>{locale === 'ar' ? 'المساحة' : 'Workspace'}</dt><dd>{route.key}</dd></div>
                {route.permission ? <div><dt>{locale === 'ar' ? 'الصلاحية' : 'Permission'}</dt><dd>{route.permission}</dd></div> : null}
                {route.connectorScope ? <div><dt>{locale === 'ar' ? 'نطاق الموصل' : 'Connector scope'}</dt><dd>{route.connectorScope}</dd></div> : null}
                {route.apiKey ? <div><dt>API</dt><dd>{route.apiKey}</dd></div> : null}
            </dl>
        </StatePanel>
    );
}

type SitesPageFilter = 'all' | 'connected' | 'review';
type SitesUrlPageState = { page: number; pageSize: number; filter: SitesPageFilter; search: string };

// The Sites grid uses the same bounded URL contract as its Laravel paginator.
// Preserve unrelated query parameters (for example a dashboard tab) and avoid
// treating a malformed user-supplied page number as a valid API request.
function isSitesRouteLocation(): boolean {
    // Avoid interpreting or mutating global URL state when this resource is
    // rendered as a preview or test outside the actual Sites route.
    return /\/sites\/?$/.test(window.location.pathname);
}

function readSitesUrlPageState(): SitesUrlPageState {
    const params = new URLSearchParams(isSitesRouteLocation() ? window.location.search : '');
    const rawPage = params.get('page');
    const number = rawPage && /^[1-9][0-9]*$/.test(rawPage) ? Number(rawPage) : 1;
    const page = Number.isSafeInteger(number) && number <= 100000 ? number : 1;
    const rawSize = params.get('per_page');
    const pageSize = rawSize && ['10', '25', '50', '100'].includes(rawSize) ? Number(rawSize) : 25;
    const rawFilter = params.get('connection_filter');
    const filter: SitesPageFilter = rawFilter === 'connected' || rawFilter === 'review' ? rawFilter : 'all';
    // Laravel accepts at most 150 characters for the Sites search request.
    // A malicious or stale URL cannot turn a harmless GET into validation errors.
    const rawSearch = params.get('search') ?? '';
    const search = rawSearch.length <= 150 ? rawSearch.trim() : '';
    return { page, pageSize, filter, search };
}

function writeSitesUrlPageState({ page, pageSize, filter, search }: SitesUrlPageState, replace = false): void {
    if (!isSitesRouteLocation()) return;
    const params = new URLSearchParams(window.location.search);
    params.set('page', String(page));
    params.set('per_page', String(pageSize));
    if (filter === 'all') params.delete('connection_filter');
    else params.set('connection_filter', filter);
    if (search) params.set('search', search);
    else params.delete('search');
    const query = params.toString();
    const target = window.location.pathname + (query ? '?' + query : '') + window.location.hash;
    if (target !== window.location.pathname + window.location.search + window.location.hash) {
        if (replace) window.history.replaceState(window.history.state, '', target);
        else window.history.pushState(window.history.state, '', target);
    }
}

function ResourceContent({ context, route }: { context: FrontendContext; route: WorkspaceRoute }) {
    const { locale, text } = useLocale();
    const { notify } = useToast();
    const queryClient = useQueryClient();
    const [searchInput, setSearchInput] = useState(() => route.key === 'sites' ? readSitesUrlPageState().search : route.key === 'execution' ? readExecutionUrlState().search : '');
    const [search, setSearch] = useState(() => route.key === 'sites' ? readSitesUrlPageState().search : route.key === 'execution' ? readExecutionUrlState().search : '');
    const [page, setPage] = useState(() => route.key === 'sites' ? readSitesUrlPageState().page : route.key === 'execution' ? readExecutionUrlState().page : 1);
    // Only enable page-size changes on APIs with verified server-side per_page.
    const [explorerPageSize, setExplorerPageSize] = useState(25);
    const [emailHistoryPageSize, setEmailHistoryPageSize] = useState(25);
    const [applicationUsersPageSize, setApplicationUsersPageSize] = useState(25);
    const [approvalPageSize, setApprovalPageSize] = useState(25);
    const [evidencePageSize, setEvidencePageSize] = useState(25);
    const [emailSchedulesPageSize, setEmailSchedulesPageSize] = useState(25);
    const [notificationsPageSize, setNotificationsPageSize] = useState(25);
    const [logsPageSize, setLogsPageSize] = useState(25);
    const [sitesPageSize, setSitesPageSize] = useState(() => readSitesUrlPageState().pageSize);
    const [executionState, setExecutionState] = useState<ExecutionUrlState>(readExecutionUrlState);
    const [executionDraft, setExecutionDraft] = useState<ExecutionUrlState>(readExecutionUrlState);
    const [wordpressSiteRoleDraft, setWordpressSiteRoleDraft] = useState<WordPressSiteUserRole>('all');
    const [wordpressSiteRoleApplied, setWordpressSiteRoleApplied] = useState<WordPressSiteUserRole>('all');
    const [mediaDraft, setMediaDraft] = useState(defaultGlobalMediaFilters);
    const [mediaAppliedFilters, setMediaAppliedFilters] = useState(defaultGlobalMediaFilters);
    const [appDataGridSortDescending, setAppDataGridSortDescending] = useState(false);
    const applicationUsersSearchInFlight = useRef(false);
    const [sitesFilter, setSitesFilter] = useState<SitesPageFilter>(() => readSitesUrlPageState().filter);

    // Browser Back/Forward restores the actual server query, not merely the
    // highlighted filter button. Other resource routes retain their own state.
    useEffect(() => {
        if (route.key !== 'sites') return undefined;
        const restore = () => {
            const restored = readSitesUrlPageState();
            setPage(restored.page);
            setSitesPageSize(restored.pageSize);
            setSitesFilter(restored.filter);
            setSearchInput(restored.search);
            setSearch(restored.search);
        };
        window.addEventListener('popstate', restore);
        return () => window.removeEventListener('popstate', restore);
    }, [route.key]);

    const navigateSites = (nextPage: number, nextSize: number, nextFilter: SitesPageFilter, nextSearch = search) => {
        writeSitesUrlPageState({ page: nextPage, pageSize: nextSize, filter: nextFilter, search: nextSearch });
        setPage(nextPage);
        setSitesPageSize(nextSize);
        setSitesFilter(nextFilter);
    };
    const navigateExecution = (next: ExecutionUrlState): void => {
        writeExecutionUrlState(next);
        setExecutionState(next);
        setExecutionDraft(next);
        setSearchInput(next.search);
        setSearch(next.search);
        setPage(next.page);
    };

    useEffect(() => {
        if (route.key !== 'execution') return undefined;
        const restore = () => {
            const next = readExecutionUrlState();
            setExecutionState(next);
            setExecutionDraft(next);
            setSearchInput(next.search);
            setSearch(next.search);
            setPage(next.page);
        };
        window.addEventListener('popstate', restore);
        return () => window.removeEventListener('popstate', restore);
    }, [route.key]);

    const [explorerPreview, setExplorerPreview] = useState<Record<string, unknown> | null>(null);
    const [explorerSelectedKeys, setExplorerSelectedKeys] = useState<Set<string>>(() => new Set());

    useEffect(() => {
        if (route.key !== 'explorer') return undefined;
        const resetExplorerLocalState = () => {
            setExplorerPreview(null);
            setExplorerSelectedKeys(new Set());
        };
        window.addEventListener(CONTENT_EXPLORER_CONTENT_TAB_RESET_EVENT, resetExplorerLocalState);
        return () => window.removeEventListener(CONTENT_EXPLORER_CONTENT_TAB_RESET_EVENT, resetExplorerLocalState);
    }, [route.key]);
    const [siteOperationsFilters, setSiteOperationsFilters] = useState<SiteOperationsFilterState>(() => siteOperationsDefaultFilters());
    const [siteOperationsExportResetToken, setSiteOperationsExportResetToken] = useState(0);
    const [siteReliabilityDraft, setSiteReliabilityDraft] = useState<SiteReliabilityFilterState>(() => siteReliabilityDefaultFilters());
    const [siteReliabilityFilters, setSiteReliabilityFilters] = useState<SiteReliabilityFilterState>(() => siteReliabilityDefaultFilters());
    const [taxonomyType, setTaxonomyType] = useState<'all' | 'category' | 'post_tag'>('category');
    const [taxonomyDraft, setTaxonomyDraft] = useState(defaultGlobalTaxonomyApplyFilters);
    const [taxonomyApplied, setTaxonomyApplied] = useState(defaultGlobalTaxonomyApplyFilters);
    const [wordpressUsersRole, setWordpressUsersRole] = useState<GlobalUsersRole>('all');

    useEffect(() => {
        if (route.key !== 'taxonomy') return;
        const requestedTaxonomyType = requestedTaxonomyTypeFromLocation();
        if (requestedTaxonomyType === 'post_tag') {
            setPage(1);
            setTaxonomyType('post_tag');
        }
    }, [route.key]);
    const [emailHistoryDraft, setEmailHistoryDraft] = useState<EmailHistoryFilterState>({ siteId: '', status: '', correlation: '' });
    const [emailHistoryFilters, setEmailHistoryFilters] = useState<EmailHistoryFilterState>({ siteId: '', status: '', correlation: '' });
    const [dialog, setDialog] = useState<{ key: string; contract: ActionContract } | null>(null);
    const state = resolveCapability(context, route);
    const routeEndpoint = route.apiKey ? context.api[route.apiKey] : undefined;
    const endpoint = route.key === 'media'
        ? (authoritativeGlobalMediaApplyEndpoint(context) ?? undefined)
        : routeEndpoint;
    const readOperations = COLLECTION_READ_OPERATIONS[route.key];
    const siteUsersManagerSearch = route.key === 'wp-users'
        && isAuthorizedWordPressSiteUsersSearch(context, window.location.search);
    const canEditExplorerContent = context.permissions.includes('*') || context.permissions.includes('content.edit');

    const selectAllExplorerFiltered = async (): Promise<void> => {
        if (route.key !== 'explorer' || !canEditExplorerContent) return;
        const keys = await loadAllFilteredExplorerKeys({ context, endpoint, search });
        setExplorerSelectedKeys(keys);
    };

    const taxonomyFilter: Record<string, string> = route.key === 'taxonomy' && taxonomyType !== 'all' ? { taxonomy: taxonomyType } : {};
    const taxonomyAppliedQuery: Record<string, string> = route.key === 'taxonomy' ? { ...taxonomyFilter, ...globalTaxonomyApplyQuery(taxonomyApplied) } : taxonomyFilter;
    const wordpressUsersFilters: Record<string, string> = route.key === 'wp-users' && wordpressUsersRole !== 'all' ? { role: wordpressUsersRole } : {};
    const emailHistoryQueryFilters: Record<string, string> = route.key === 'email-history' ? {
        ...(emailHistoryFilters.siteId ? { site_id: emailHistoryFilters.siteId } : {}),
        ...(emailHistoryFilters.status ? { status: emailHistoryFilters.status } : {}),
        ...(emailHistoryFilters.correlation.trim() ? { correlation: emailHistoryFilters.correlation.trim() } : {}),
        sort_desc: appDataGridSortDescending ? '1' : '0',
        per_page: String(emailHistoryPageSize),
    } : {};
    const emailHistoryActiveFilterCount = route.key === 'email-history'
        ? [emailHistoryFilters.siteId, emailHistoryFilters.status, emailHistoryFilters.correlation.trim()].filter(Boolean).length
        : 0;
    const siteReliabilityQueryFilters: Record<string, string> = route.key === 'site-reliability' ? {
        period_days: siteReliabilityFilters.periodDays,
        minimum_operations: siteReliabilityFilters.minimumOperations,
    } : {};
    const mediaQueryFilters = route.key === 'media' ? globalMediaApplyQuery(mediaAppliedFilters) : {};
    const resourceFilters = { ...taxonomyFilter, ...emailHistoryQueryFilters, ...siteReliabilityQueryFilters, ...mediaQueryFilters };
    const readWorkspaceCollection = (url: string): Promise<unknown> =>
        readWorkspaceCollectionResponse(route.key, url, (target) => apiRequest<unknown>(target));
    const query = useQuery(route.key === 'email-history' ? {
        queryKey: ['workspace', context.tenant.slug, route.key, endpoint, page, search, taxonomyType, emailHistoryFilters.siteId, emailHistoryFilters.status, emailHistoryFilters.correlation, appDataGridSortDescending, emailHistoryPageSize],
        queryFn: () => readWorkspaceCollection(endpointWithQuery(endpoint!, page, search, resourceFilters)),
        enabled: state.state === 'enabled' && Boolean(endpoint),
    } : route.key === 'execution' ? {
        queryKey: ['workspace', context.tenant.slug, route.key, endpoint,
            executionState.page, executionState.pageSize, executionState.search,
            executionState.status, executionState.type, executionState.dateFrom, executionState.dateTo],
        queryFn: () => readWorkspaceCollection(endpointWithQuery(endpoint!, executionState.page, executionState.search, {
            per_page: String(executionState.pageSize),
            ...(executionState.status ? { status: executionState.status } : {}),
            ...(executionState.type ? { type: executionState.type } : {}),
            ...(executionState.dateFrom ? { date_from: executionState.dateFrom } : {}),
            ...(executionState.dateTo ? { date_to: executionState.dateTo } : {}),
        })),
        enabled: state.state === 'enabled' && Boolean(endpoint),
    } : route.key === 'application-users' ? {
        // The membership grid opts in to a real SQL page and filtered count.
        // Do not use the legacy unlimited array as a paginated collection.
        queryKey: ['workspace', context.tenant.slug, route.key, endpoint, page, search, applicationUsersPageSize],
        queryFn: () => readWorkspaceCollection(endpointWithQuery(endpoint!, page, search, {
            per_page: String(applicationUsersPageSize),
        })),
        enabled: state.state === 'enabled' && Boolean(endpoint),
    } : route.key === 'notifications' ? {
        // Tenant/user scoped Laravel inbox returns authoritative paginator metadata.
        queryKey: ['workspace', context.tenant.slug, route.key, endpoint, page, search, notificationsPageSize],
        queryFn: () => readWorkspaceCollection(endpointWithQuery(endpoint!, page, search, { per_page: String(notificationsPageSize) })),
        enabled: state.state === 'enabled' && Boolean(endpoint),
    } : route.key === 'email-schedules' ? {
        // This route explicitly opts into the server-paginated schedule API.
        queryKey: ['workspace', context.tenant.slug, route.key, endpoint, page, search, emailSchedulesPageSize],
        queryFn: () => readWorkspaceCollection(endpointWithQuery(endpoint!, page, search, { per_page: String(emailSchedulesPageSize) })),
        enabled: state.state === 'enabled' && Boolean(endpoint),
    } : route.key === 'logs' ? {
        // Tenant logs opt in to authoritative server paging, never the legacy 500-row sample.
        queryKey: ['workspace', context.tenant.slug, route.key, endpoint, page, search, logsPageSize],
        queryFn: () => readWorkspaceCollection(endpointWithQuery(endpoint!, page, search, { per_page: String(logsPageSize) })),
        enabled: state.state === 'enabled' && Boolean(endpoint),
    } : route.key === 'evidence' ? {
        // Evidence endpoint returns a tenant-authorized Laravel paginator.
        queryKey: ['workspace', context.tenant.slug, route.key, endpoint, page, search, evidencePageSize],
        queryFn: () => readWorkspaceCollection(endpointWithQuery(endpoint!, page, search, { per_page: String(evidencePageSize) })),
        enabled: state.state === 'enabled' && Boolean(endpoint),
    } : route.key === 'approvals' ? {
        // Approval Queue is tenant-scoped and supports authoritative server
        // pagination. Include page size in the query key to avoid stale rows.
        queryKey: ['workspace', context.tenant.slug, route.key, endpoint, page, search, approvalPageSize],
        queryFn: () => readWorkspaceCollection(endpointWithQuery(endpoint!, page, search, { per_page: String(approvalPageSize) })),
        enabled: state.state === 'enabled' && Boolean(endpoint),
    } : route.key === 'site-reliability' ? {
        queryKey: ['workspace', context.tenant.slug, route.key, endpoint, page, search, siteReliabilityFilters.periodDays, siteReliabilityFilters.minimumOperations],
        queryFn: () => readWorkspaceCollection(endpointWithQuery(endpoint!, page, search, siteReliabilityQueryFilters)),
        enabled: state.state === 'enabled' && Boolean(endpoint),
    } : route.key === 'media' ? {
        queryKey: ['workspace', context.tenant.slug, route.key, endpoint, page, search, mediaAppliedFilters.mediaType, mediaAppliedFilters.pageSize],
        queryFn: () => readWorkspaceCollection(endpointWithQuery(endpoint!, page, search, mediaQueryFilters)),
        enabled: state.state === 'enabled' && Boolean(endpoint),
    } : siteUsersManagerSearch ? {
        queryKey: ['workspace', context.tenant.slug, route.key, endpoint, page, search, wordpressSiteRoleApplied],
        queryFn: () => readWorkspaceCollection(endpointWithQuery(endpoint!, page, search,
            wordpressSiteRoleApplied === 'all' ? {} : { role: wordpressSiteRoleApplied })),
        enabled: state.state === 'enabled' && Boolean(endpoint),
    } : route.key === 'sites' ? {
        // Sites opt-in pagination keeps all legacy callers' no-query array shape.
        queryKey: ['workspace', context.tenant.slug, route.key, endpoint, page, search, sitesFilter, sitesPageSize],
        queryFn: () => readWorkspaceCollection(endpointWithQuery(endpoint!, page, search, {
            per_page: String(sitesPageSize),
            connection_filter: sitesFilter,
        })),
        enabled: state.state === 'enabled' && Boolean(endpoint),
    } : route.key === 'explorer' ? {
        // Explorer explicitly supports server-side per_page, unlike generic
        // WordPress GETs; never add unsupported query args to other workspaces.
        queryKey: ['workspace', context.tenant.slug, route.key, endpoint, page, search, explorerPageSize],
        queryFn: () => readWorkspaceCollection(endpointWithQuery(endpoint!, page, search, { per_page: String(explorerPageSize) })),
        enabled: state.state === 'enabled' && Boolean(endpoint),
    } : {
        queryKey: ['workspace', context.tenant.slug, route.key, endpoint, page, search, taxonomyType, taxonomyApplied.minimumUsage, taxonomyApplied.pageSize, wordpressUsersRole],
        queryFn: () => readWorkspaceCollection(endpointWithQuery(endpoint!, page, search, { ...taxonomyAppliedQuery, ...wordpressUsersFilters })),
        enabled: state.state === 'enabled' && Boolean(endpoint),
    });

    const resetGlobalTaxonomyFilters = async (trustedEndpoint: string): Promise<void> => {
        if (
            route.key !== 'taxonomy'
            || state.state !== 'enabled'
            || !endpoint
            || endpoint !== trustedEndpoint
            || query.isFetching
        ) {
            throw new Error('The active tenant/site taxonomy read is not authorized.');
        }

        // Source ResetAsync: remove query/type filters, return to the first page,
        // then perform a fresh tenant/site-scoped authoritative GET.
        setSearchInput('');
        setSearch('');
        setTaxonomyType('all');
        setTaxonomyDraft(defaultGlobalTaxonomyApplyFilters());
        setTaxonomyApplied(defaultGlobalTaxonomyApplyFilters());
        setPage(1);
        await queryClient.fetchQuery({
            queryKey: ['workspace', context.tenant.slug, route.key, trustedEndpoint, 1, '', 'all'],
            queryFn: () => apiRequest<unknown>(endpointWithQuery(trustedEndpoint, 1, '')),
            staleTime: 0,
        });
    };

    const applyGlobalTaxonomyFilters = async (trustedEndpoint: string): Promise<void> => {
        if (route.key !== 'taxonomy' || state.state !== 'enabled' || !endpoint
            || endpoint !== trustedEndpoint || query.isFetching) {
            throw new Error('The active tenant/site taxonomy read is not authorized.');
        }
        // Source ApplyAsync: the selected filters are applied to page one and
        // the authoritative API is reread even if the selected values are unchanged.
        const nextSearch = searchInput.trim();
        const nextFilters = { ...taxonomyDraft };
        setPage(1);
        setSearch(nextSearch);
        setTaxonomyApplied(nextFilters);
        await queryClient.fetchQuery({
            queryKey: ['workspace', context.tenant.slug, route.key, trustedEndpoint, 1, nextSearch, taxonomyType, nextFilters.minimumUsage, nextFilters.pageSize],
            queryFn: () => apiRequest<unknown>(endpointWithQuery(trustedEndpoint, 1, nextSearch,
                { ...taxonomyFilter, ...globalTaxonomyApplyQuery(nextFilters) })),
            staleTime: 0,
        });
    };

    const resetContentExplorerFilters = async (trustedEndpoint: string): Promise<void> => {
        if (
            route.key !== 'explorer'
            || state.state !== 'enabled'
            || !endpoint
            || endpoint !== trustedEndpoint
        ) {
            throw new Error('The active tenant/site Explorer read is not authorized.');
        }

        // Match the source ResetFiltersAsync contract: reset query/type/status to defaults,
        // return to page one, and re-read authoritative tenant/site data.
        // The generic Explorer grid has only a search draft; type/status live in its
        // separate apply-filters control and are not claimed by this operation.
        setSearchInput('');
        setSearch('');
        setPage(1);
        setExplorerPreview(null);
        setExplorerSelectedKeys(new Set());

        await queryClient.fetchQuery({
            queryKey: ['workspace', context.tenant.slug, route.key, trustedEndpoint, 1, '', taxonomyType],
            queryFn: () => apiRequest<unknown>(endpointWithQuery(trustedEndpoint, 1, '')),
            staleTime: 0,
        });
    };

    const applyGlobalMediaFilters = async (): Promise<void> => {
        if (route.key !== 'media' || state.state !== 'enabled' || !endpoint || query.isFetching) return;
        const nextSearch = searchInput.trim();
        const unchanged = page === 1 && search === nextSearch
            && mediaAppliedFilters.mediaType === mediaDraft.mediaType
            && mediaAppliedFilters.pageSize === mediaDraft.pageSize;
        // The source ApplyAsync always resets pagination before its authoritative read.
        // Keep the selected site constrained to the server-authorized active tenant/site.
        setPage(1);
        setSearch(nextSearch);
        setMediaAppliedFilters({ ...mediaDraft });
        if (unchanged) {
            const refreshed = await query.refetch();
            if (refreshed.error) throw refreshed.error;
        }
    };

    // Canonical GlobalMediaExplorer ResetAsync: reset draft/applied filters and
    // reread the authoritative media collection scoped to the active tenant/site.
    const resetGlobalMediaFilters = async (): Promise<void> => {
        if (route.key !== 'media' || state.state !== 'enabled' || !endpoint || query.isFetching) return;
        const reset = globalMediaResetState();
        const defaults = defaultGlobalMediaFilters();
        setSearchInput(reset.search);
        setSearch(reset.search);
        setPage(reset.page);
        setMediaDraft(defaults);
        setMediaAppliedFilters(defaults);
        await queryClient.fetchQuery({
            queryKey: ['workspace', context.tenant.slug, route.key, endpoint, reset.page, reset.search, defaults.mediaType, defaults.pageSize],
            queryFn: () => apiRequest<unknown>(endpointWithQuery(endpoint, reset.page, reset.search, {
                per_page: String(reset.pageSize),
            })),
            staleTime: 0,
        });
    };

    const applicationUsersSearchAuthorized = context.permissions.includes('*') || context.permissions.includes('members.manage');
    const searchApplicationUsers = async (): Promise<void> => {
        if (
            route.key !== 'application-users'
            || !endpoint
            || query.isFetching
            || applicationUsersSearchInFlight.current
            || !applicationUsersSearchAuthorized
        ) return;

        const nextSearch = searchInput.trim();
        applicationUsersSearchInFlight.current = true;
        setPage(1);
        setSearch(nextSearch);
        try {
            await queryClient.fetchQuery({
                queryKey: ['workspace', context.tenant.slug, route.key, endpoint, 1, nextSearch, applicationUsersPageSize],
                queryFn: () => apiRequest<unknown>(endpointWithQuery(endpoint, 1, nextSearch, {
                    per_page: String(applicationUsersPageSize),
                })),
                staleTime: 0,
            });
        } finally {
            applicationUsersSearchInFlight.current = false;
        }
    };

    const applicationUsersClearAuthorized = applicationUsersSearchAuthorized;
    const clearApplicationUsersSearch = async (): Promise<void> => {
        if (route.key !== 'application-users' || !endpoint || query.isFetching || !applicationUsersClearAuthorized) return;

        setSearchInput('');
        setSearch('');
        setPage(1);
        await queryClient.fetchQuery({
            queryKey: ['workspace', context.tenant.slug, route.key, endpoint, 1, '', applicationUsersPageSize],
            queryFn: () => apiRequest<unknown>(endpointWithQuery(endpoint, 1, '', {
                per_page: String(applicationUsersPageSize),
            })),
        });
    };

    const clearAdminBillingSupportSearch = async (): Promise<void> => {
        if (route.key !== 'admin-billing-support' || !endpoint || query.isFetching) return;

        setSearchInput('');
        setSearch('');
        setPage(1);
        setDialog(null);
        await queryClient.fetchQuery({
            queryKey: ['workspace', context.tenant.slug, route.key, endpoint, 1, ''],
            queryFn: () => apiRequest<unknown>(endpointWithQuery(endpoint, 1, '')),
            staleTime: 0,
        });
    };

    const resetSiteOperationsFilters = (): void => {
        if (route.key !== 'site-operations') return;
        setSearchInput('');
        setSearch('');
        setPage(1);
        setSiteOperationsFilters(siteOperationsDefaultFilters());
        setSiteOperationsExportResetToken((current) => current + 1);
    };

    const applyEmailHistoryFilters = async (): Promise<void> => {
        if (route.key !== 'email-history' || !endpoint || query.isFetching) return;
        const next = { ...emailHistoryDraft, correlation: emailHistoryDraft.correlation.trim() };
        const unchanged = page === 1
            && next.siteId === emailHistoryFilters.siteId
            && next.status === emailHistoryFilters.status
            && next.correlation === emailHistoryFilters.correlation;
        setPage(1);
        setEmailHistoryFilters(next);
        if (unchanged) await query.refetch();
    };

    const applySiteReliabilityFilters = async (next: SiteReliabilityFilterState): Promise<void> => {
        if (route.key !== 'site-reliability' || !endpoint || query.isFetching) return;
        const unchanged = page === 1
            && next.periodDays === siteReliabilityFilters.periodDays
            && next.minimumOperations === siteReliabilityFilters.minimumOperations;
        setPage(1);
        setSiteReliabilityFilters(next);
        if (unchanged) await query.refetch();
    };

    const clearEmailHistoryGridFilters = async (): Promise<void> => {
        if (route.key !== 'email-history' || !endpoint || query.isFetching) return;

        const cleared: EmailHistoryFilterState = { siteId: '', status: '', correlation: '' };
        setEmailHistoryDraft(cleared);
        setEmailHistoryFilters(cleared);
        setSearchInput('');
        setSearch('');
        setPage(1);
        await queryClient.fetchQuery({
            queryKey: ['workspace', context.tenant.slug, route.key, endpoint, 1, '', taxonomyType, '', '', '', appDataGridSortDescending],
            queryFn: () => apiRequest<unknown>(endpointWithQuery(endpoint, 1, '', { sort_desc: appDataGridSortDescending ? '1' : '0' })),
            staleTime: 0,
        });
    };

    const refreshWorkspace = async (): Promise<void> => {
        if (route.key === 'site-operations') {
            await runAuthoritativeSiteOperationsReload({
                busy: query.isFetching,
                refetch: () => query.refetch(),
            });
            return;
        }

        if (route.key === 'site-reliability') {
            await runAuthoritativeSiteReliabilityReload({
                busy: query.isFetching,
                refetch: () => query.refetch(),
            });
            return;
        }

        if (route.key !== 'backups') {
            await query.refetch();
            return;
        }

        await runAuthoritativeBackupReload({
            busy: query.isFetching,
            refetch: () => query.refetch(),
            onSuccess: () => notify(
                locale === 'ar'
                    ? 'تمت إعادة تحميل النسخ الاحتياطية من الحالة الموثوقة على الخادم.'
                    : 'Backups were reloaded from authoritative server state.',
                'success',
            ),
            onFailure: (error) => notify(
                error instanceof Error
                    ? error.message
                    : (locale === 'ar' ? 'تعذر إعادة تحميل النسخ الاحتياطية من الخادم.' : 'Backups could not be reloaded from the server.'),
                'error',
            ),
        });
    };

    const mutation = useMutation({
        mutationFn: async (payload: Record<string, string | number>) => {
            if (!dialog) throw new Error('Action contract is missing.');
            const request = prepareActionRequest(dialog.contract, context, payload);
            return mutateThenReconcile(
                () => apiRequest(request.endpoint, { method: request.method, body: request.body, headers: request.headers }),
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
                notify(
                    locale === 'ar'
                        ? 'قبل الخادم العملية، لكن تعذر تحديث الحالة الموثوقة. أعد تحميل الشاشة قبل تكرار العملية.'
                        : error.message,
                    'error',
                );
                return;
            }
            notify(error instanceof Error ? error.message : (locale === 'ar' ? 'فشلت العملية.' : 'The operation failed.'), 'error');
        },
    });

    const closeActionDialog = () => {
        if (mutation.isPending) return;
        mutation.reset();
        setDialog(null);
    };

    // The API clamps a requested page that no longer exists (for example after
    // deleting the final item). Reflect only that *authoritative* response in
    // history, without creating an extra Back entry or changing other routes.
    useEffect(() => {
        if (route.key !== 'sites' || !query.data || query.isFetching) return;
        if (Array.isArray(query.data) || typeof query.data !== 'object') return;
        const response = query.data as CollectionEnvelope;
        const last = response.last_page ?? response.meta?.last_page;
        const current = response.current_page ?? response.meta?.current_page;
        if (!Number.isSafeInteger(last) || !Number.isSafeInteger(current)
            || last! < 1 || current! < 1 || current! !== last || page <= last!) return;
        writeSitesUrlPageState({ page: current!, pageSize: sitesPageSize, filter: sitesFilter, search }, true);
        setPage(current!);
    }, [route.key, query.data, query.isFetching, page, sitesPageSize, sitesFilter, search]);

    if (state.state !== 'enabled') return <Unavailable route={route} context={context} state={state} />;
    if (query.isLoading) return <LoadingState />;
    if (query.error) return (
        <div className="workspace-stack">
            <QueryError error={query.error} retry={() => query.refetch()} />
            {route.key === 'media' ? (
                <MediaBackToSitesControl tenantSlug={context.tenant.slug} error={query.error} />
            ) : null}
            {route.key === 'site-operations' ? <SiteOperationsSitesLinkControl context={context} /> : null}
            <CurrentUserLogsControl context={context} />
        </div>
    );

    const collection = normalizeCollection(query.data);
    const observabilityMeta = route.key === 'diagnostics' && query.data && typeof query.data === 'object' && !Array.isArray(query.data)
        ? (query.data as { meta?: { failed_operations_count?: number; recent_audit_count?: number;
            failed_operations_total?: number; audit_events_total?: number;
            failed_operations_truncated?: boolean; recent_audit_truncated?: boolean;
            empty_reason?: string; scope?: string } }).meta
        : undefined;
    const logsMeta = route.key === 'logs' && query.data && typeof query.data === 'object' && !Array.isArray(query.data)
        ? (query.data as { meta?: {
            scope: string; tenant_records: number; matching_records: number; returned_records: number;
            truncated: boolean; empty_reason: string;
        } }).meta
        : undefined;
    const visibleRows = route.key === 'sites'
        ? (sitesFilter === 'connected'
            ? collection.rows.filter(isSiteConnectedRow)
            : sitesFilter === 'review'
                ? collection.rows.filter(isSiteReviewRow)
                : collection.rows)
        : route.key === 'site-operations'
            ? filterSiteOperationsRows(collection.rows, search, siteOperationsFilters)
            : collection.rows;
    const sitesCounts = route.key === 'sites' && query.data && typeof query.data === 'object' && !Array.isArray(query.data)
        ? (query.data as { meta?: { connected_count?: number; review_count?: number } }).meta : undefined;
    const connectedSiteCount = route.key === 'sites'
        ? sitesCounts?.connected_count ?? collection.rows.filter(isSiteConnectedRow).length : 0;
    const reviewSiteCount = route.key === 'sites'
        ? sitesCounts?.review_count ?? collection.rows.filter(isSiteReviewRow).length : 0;
    const serverErrors = mutation.error instanceof ApiError ? mutation.error.validation : {};
    const aiCenterMeta = route.key === 'ai-center' && query.data && typeof query.data === 'object'
        ? ((query.data as CollectionEnvelope).meta ?? null)
        : null;
    const aiCenterSites = Array.isArray(aiCenterMeta?.sites) ? aiCenterMeta.sites : [];
    const refreshOperationId = route.key === 'comments'
        ? COMMENTS_REFRESH_CLICK_OPERATION_ID
        : route.key === 'notifications'
        ? NOTIFICATION_INBOX_RELOAD_OPERATION_ID
        : route.key === 'site-operations'
        ? SITE_OPERATIONS_RELOAD_OPERATION_ID
        : route.key === 'site-reliability'
            ? SITE_RELIABILITY_RELOAD_OPERATION_ID
            : (AUTOMATION_PHASE_REFRESH_OPERATIONS[route.key]
                ?? (route.key === 'ai-center'
                    ? AI_CENTER_METADATA_REFRESH_OPERATION_ID
                    : (route.key === 'sites' ? SITES_RELOAD_OPERATION_ID : undefined)));

    return (
        <div className={route.key === 'ai-center' ? 'workspace-stack ai-center-resource-grid' : 'workspace-stack'}>
            <section className="toolbar-panel" aria-label={locale === 'ar' ? 'أدوات مساحة العمل' : 'Workspace tools'}>
                {route.key === 'email-history' ? (
                    <EmailHistoryFiltersControl
                        value={emailHistoryDraft}
                        locale={locale}
                        busy={query.isFetching}
                        onChange={setEmailHistoryDraft}
                        onApply={() => { void applyEmailHistoryFilters(); }}
                    />
                ) : null}
                {route.key === 'email-history' ? (
                    <AppDataGridClearFiltersControl
                        locale={locale}
                        canonicalOperationAlias={EMAIL_HISTORY_CLEAR_FILTERS_OPERATION_ID}
                        searchValue={search}
                        activeFilterCount={emailHistoryActiveFilterCount}
                        busy={query.isFetching}
                        onSearchInputChange={setSearchInput}
                        onPageChange={setPage}
                        onSearchChanged={setSearch}
                        onClearFiltersRequested={clearEmailHistoryGridFilters}
                    />
                ) : null}
                {route.key === 'site-operations' ? (
                    <SiteOperationsFiltersControl
                        rows={collection.rows}
                        locale={locale}
                        value={siteOperationsFilters}
                        onChange={setSiteOperationsFilters}
                        onReset={resetSiteOperationsFilters}
                    />
                ) : null}
                {route.key === 'site-reliability' ? (
                    <SiteReliabilityFiltersControl
                        locale={locale}
                        value={siteReliabilityDraft}
                        busy={query.isFetching}
                        onChange={setSiteReliabilityDraft}
                        onApply={(next) => { void applySiteReliabilityFilters(next); }}
                    />
                ) : null}
                {route.key === 'media' ? (
                    <GlobalMediaSyncLinkControl context={context} mediaAvailable={Boolean(endpoint)} />
                ) : null}
                {route.key === 'media' ? (
                    <GlobalMediaResetControl
                        locale={locale}
                        busy={query.isFetching}
                        available={Boolean(endpoint)}
                        onReset={() => { void resetGlobalMediaFilters().catch((error) => {
                            notify(error instanceof Error ? error.message : 'Media filters could not be reset.', 'error');
                        }); }}
                    />
                ) : null}
                {route.key !== 'diagnostics' ? (<form className="search-form" onSubmit={(event) => {
                    event.preventDefault();
                    if (route.key === 'application-users') {
                        void searchApplicationUsers().catch(() => undefined);
                        return;
                    }
                    if (route.key === 'taxonomy') {
                        const trusted = authorizedGlobalTaxonomyResetEndpoint(context, endpoint);
                        if (trusted) void applyGlobalTaxonomyFilters(trusted).catch((error) => {
                            notify(error instanceof Error ? error.message : 'Taxonomy filters could not be applied.', 'error');
                        });
                        return;
                    }
                    if (route.key === 'media') {
                        void applyGlobalMediaFilters().catch((error) => {
                            notify(error instanceof Error ? error.message : 'Media filters could not be applied.', 'error');
                        });
                        return;
                    }
                    if (siteUsersManagerSearch) {
                        if (query.isFetching) return;
                        const nextSearch = searchInput.trim();
                        const unchanged = page === 1 && search === nextSearch && wordpressSiteRoleApplied === wordpressSiteRoleDraft;
                        setPage(1);
                        setSearch(nextSearch);
                        setWordpressSiteRoleApplied(wordpressSiteRoleDraft);
                        // UsersManager SearchClickedAsync always re-reads, even when the filters are unchanged.
                        if (unchanged) void query.refetch();
                        return;
                    }
                    if (route.key === 'execution') {
                        navigateExecution({
                            ...executionDraft,
                            page: 1,
                            search: searchInput.trim().slice(0, 120),
                        });
                        return;
                    }
                    if (route.key === 'sites') {
                        const nextSearch = searchInput.trim().slice(0, 150);
                        setSearchInput(nextSearch);
                        setSearch(nextSearch);
                        navigateSites(1, sitesPageSize, sitesFilter, nextSearch);
                        return;
                    }
                    setPage(1); setSearch(searchInput); if (route.key === 'explorer') setExplorerSelectedKeys(new Set());
                }} role="search">
                    {route.key === 'execution' ? (
                        <span className="toolbar-actions">
                            <label>{locale === 'ar' ? 'الحالة' : 'Status'}
                                <select aria-label={locale === 'ar' ? 'حالة التنفيذ' : 'Execution status'}
                                    value={executionDraft.status}
                                    onChange={(event) => setExecutionDraft((current) => ({ ...current, status: event.target.value }))}>
                                    {EXECUTION_STATUSES.map((status) => (
                                        <option key={status} value={status}>
                                            {status || (locale === 'ar' ? 'جميع الحالات' : 'All statuses')}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label>{locale === 'ar' ? 'نوع العملية' : 'Operation type'}
                                <input aria-label={locale === 'ar' ? 'نوع العملية' : 'Operation type'}
                                    value={executionDraft.type}
                                    maxLength={80}
                                    onChange={(event) => setExecutionDraft((current) => ({ ...current, type: event.target.value }))} />
                            </label>
                            <label>{locale === 'ar' ? 'من تاريخ' : 'From date'}
                                <input type="date" aria-label={locale === 'ar' ? 'من تاريخ' : 'From date'}
                                    value={executionDraft.dateFrom}
                                    onChange={(event) => setExecutionDraft((current) => ({ ...current, dateFrom: event.target.value }))} />
                            </label>
                            <label>{locale === 'ar' ? 'إلى تاريخ' : 'To date'}
                                <input type="date" aria-label={locale === 'ar' ? 'إلى تاريخ' : 'To date'}
                                    value={executionDraft.dateTo}
                                    onChange={(event) => setExecutionDraft((current) => ({ ...current, dateTo: event.target.value }))} />
                            </label>
                        </span>
                    ) : null}
                    <label className="sr-only" htmlFor={`search-${route.key}`}>{text(commonText.search)}</label>
                    {route.key === 'comments' ? (
                        <AppSearchBox
                            id={`search-${route.key}`}
                            value={searchInput}
                            onValueChange={(value) => {
                                setSearchInput(value);
                                if (value === '' && search !== '') {
                                    setPage(1);
                                    setSearch('');
                                }
                            }}
                            placeholder={locale === 'ar' ? 'بحث في البيانات الحية…' : 'Search live data…'}
                            ariaLabel={text(commonText.search)}
                            clearAriaLabel={locale === 'ar' ? 'مسح البحث' : 'Clear search'}
                            busy={query.isFetching}
                            busyText={locale === 'ar' ? 'جارٍ البحث' : 'Searching'}
                        />
                    ) : (
                        <input id={`search-${route.key}`} value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder={locale === 'ar' ? 'بحث في البيانات الحية…' : 'Search live data…'} />
                    )}
                    {siteUsersManagerSearch ? (
                        <label>
                            {locale === 'ar' ? 'الدور' : 'Role'}
                            <select
                                aria-label={locale === 'ar' ? 'دور مستخدم WordPress' : 'WordPress user role'}
                                value={wordpressSiteRoleDraft}
                                onChange={(event) => setWordpressSiteRoleDraft(normalizeWordPressSiteUserRole(event.target.value))}
                                disabled={query.isFetching}
                            >
                                {WORDPRESS_SITE_USER_ROLES.map((role) => (
                                    <option value={role} key={role}>
                                        {role === 'all' ? (locale === 'ar' ? 'كل الأدوار' : 'All roles') : role}
                                    </option>
                                ))}
                            </select>
                        </label>
                    ) : null}
                    {route.key === 'media' ? (
                        <GlobalMediaApplyControl
                            locale={locale}
                            value={mediaDraft}
                            busy={query.isFetching}
                            available={Boolean(endpoint)}
                            onTypeChange={(mediaType) => setMediaDraft((current) => ({ ...current, mediaType }))}
                            onPageSizeChange={(pageSize) => setMediaDraft((current) => ({ ...current, pageSize }))}
                        />
                    ) : route.key === 'application-users' ? (
                        <ApplicationUsersSearchControl
                            busy={query.isFetching || applicationUsersSearchInFlight.current}
                            locale={locale}
                            authorized={applicationUsersSearchAuthorized}
                        />
                    ) : route.key === 'taxonomy' ? null : (
                        <button
                            type="submit"
                            className="btn"
                            data-canonical-operation={route.key === 'wp-users'
                                ? (siteUsersManagerSearch ? WORDPRESS_SITE_USERS_SEARCH_OPERATION_ID : WORDPRESS_USERS_SEARCH_OPERATION_ID)
                                : (route.key === 'comments' ? COMMENTS_SEARCH_OPERATION_ID : undefined)}
                            disabled={(route.key === 'comments' || route.key === 'wp-users') && query.isFetching}
                            aria-busy={(route.key === 'comments' || route.key === 'wp-users') && query.isFetching ? 'true' : 'false'}
                        >
                            {text(commonText.search)}
                        </button>
                    )}
                    {route.key === 'application-users' ? (
                        <ApplicationUsersClearSearchControl
                            searchValue={searchInput}
                            busy={query.isFetching}
                            locale={locale}
                            authorized={applicationUsersClearAuthorized}
                            onClearRequested={clearApplicationUsersSearch}
                        />
                    ) : null}
                    {route.key === 'taxonomy' ? (
                        <GlobalTaxonomyApplyControl
                            context={context}
                            endpoint={endpoint}
                            value={taxonomyDraft}
                            busy={query.isFetching}
                            onChange={setTaxonomyDraft}
                            onApplyRequested={applyGlobalTaxonomyFilters}
                        />
                    ) : null}
                    {route.key === 'taxonomy' ? (
                        <GlobalTaxonomyResetControl
                            context={context}
                            endpoint={endpoint}
                            busy={query.isFetching}
                            onResetRequested={resetGlobalTaxonomyFilters}
                        />
                    ) : null}
                    {route.key === 'explorer' ? (
                        <ContentExplorerResetFiltersControl
                            context={context}
                            busy={query.isFetching}
                            onResetRequested={resetContentExplorerFilters}
                        />
                    ) : null}
                    {route.key === 'admin-billing-support' ? (
                        <AdminBillingSupportClearSearchControl
                            busy={query.isFetching}
                            locale={locale}
                            available={Boolean(endpoint)}
                            onClearRequested={clearAdminBillingSupportSearch}
                        />
                    ) : null}
                </form>) : null}
                <div className="toolbar-actions">
                    {route.key === 'site-operations' ? (
                        <SiteOperationsSitesLinkControl context={context} />
                    ) : null}
                    {route.key === 'sites' ? (
                        <SitesSystemHealthLinkControl context={context} />
                    ) : null}
                    {route.key === 'application-users' ? (
                        <>
                            <ApplicationUsersRolesPermissionsLinkControl context={context} />
                            <ApplicationUsersStartCreateControl
                                context={context}
                                busy={query.isFetching}
                                onCreated={async () => {
                                    const refreshed = await query.refetch();
                                    if (refreshed.error) throw refreshed.error;
                                }}
                            />
                        </>
                    ) : null}
                    {route.key === 'roles' ? (
                        <>
                            <RolesApplicationUsersLinkControl context={context} />
                            <RolesStartCreateControl
                                context={context}
                                busy={query.isFetching}
                                onCreated={async () => {
                                    const refreshed = await query.refetch();
                                    if (refreshed.error) throw refreshed.error;
                                }}
                            />
                        </>
                    ) : null}
                    {route.key === 'sites' ? (
                        <button
                            type="button"
                            className="btn"
                            data-canonical-operation={SITES_SHOW_ALL_OPERATION_ID}
                            aria-pressed={sitesFilter === 'all'}
                            onClick={() => navigateSites(1, sitesPageSize, 'all')}
                        >{locale === 'ar' ? 'الكل' : 'All'}</button>
                    ) : null}
                    {route.key === 'sites' ? (
                        <button
                            type="button"
                            className="btn"
                            data-canonical-operation={SITES_SHOW_CONNECTED_OPERATION_ID}
                            aria-pressed={sitesFilter === 'connected'}
                            onClick={() => navigateSites(1, sitesPageSize, 'connected')}
                        >{locale === 'ar' ? 'متصل' : 'Connected'} <b>{connectedSiteCount}</b></button>
                    ) : null}
                    {route.key === 'sites' ? (
                        <button
                            type="button"
                            className="btn"
                            data-canonical-operation={SITES_SHOW_REVIEW_OPERATION_ID}
                            aria-pressed={sitesFilter === 'review'}
                            onClick={() => navigateSites(1, sitesPageSize, 'review')}
                        >{locale === 'ar' ? 'يحتاج مراجعة' : 'Needs review'} <b>{reviewSiteCount}</b></button>
                    ) : null}
                    {route.key === 'taxonomy' ? (
                        <button
                            type="button"
                            className="btn"
                            data-canonical-operation="AIMW-TAXO-D5A5B530C7"
                            aria-pressed={taxonomyType === 'category'}
                            onClick={() => {
                                setPage(1);
                                if (taxonomyType === 'category') {
                                    void query.refetch();
                                } else {
                                    setTaxonomyType('category');
                                }
                            }}
                        >{locale === 'ar' ? 'التصنيفات' : 'Categories'}</button>
                    ) : null}
                    {route.key === 'taxonomy' ? (
                        <button
                            type="button"
                            className="btn"
                            data-canonical-operation="AIMW-TAXO-D784149312"
                            aria-pressed={taxonomyType === 'post_tag'}
                            onClick={() => {
                                setPage(1);
                                if (taxonomyType === 'post_tag') {
                                    void query.refetch();
                                } else {
                                    setTaxonomyType('post_tag');
                                }
                            }}
                        >{locale === 'ar' ? 'الوسوم' : 'Tags'}</button>
                    ) : null}
                    {route.key === 'wp-users' ? (
                        <>
                        <GlobalUsersRefreshControl
                            context={context}
                            endpoint={endpoint}
                            role={wordpressUsersRole}
                            busy={query.isFetching}
                            onRoleChange={(nextRole) => { setPage(1); setWordpressUsersRole(nextRole); }}
                            onRefreshRequested={async (trustedEndpoint) => {
                                if (state.state !== 'enabled' || trustedEndpoint !== endpoint) {
                                    throw new Error('The active tenant/site WordPress users endpoint is not authorized.');
                                }
                                const refreshed = await query.refetch();
                                if (refreshed.error) throw refreshed.error;
                            }}
                        />
                        <WordPressUsersRefreshControl
                            context={context}
                            endpoint={endpoint}
                            busy={query.isFetching}
                            onReload={async () => {
                                const refreshed = await query.refetch();
                                if (refreshed.error) throw refreshed.error;
                            }}
                        />
                        </>
                    ) : (
                    <button
                        type="button"
                        className="btn"
                        data-canonical-operation={refreshOperationId}
                        data-canonical-load-operation={readOperations?.load}
                        data-canonical-refresh-operation={readOperations?.refresh}
                        disabled={(route.key === 'comments' || route.key === 'ai-center' || route.key === 'backups' || route.key === 'site-operations' || route.key === 'site-reliability' || route.key === 'execution') && query.isFetching}
                        aria-busy={(route.key === 'comments' || route.key === 'ai-center' || route.key === 'backups' || route.key === 'site-operations' || route.key === 'site-reliability' || route.key === 'execution') && query.isFetching ? 'true' : 'false'}
                        onClick={() => void refreshWorkspace()}
                    >
                        {route.key === 'ai-center'
                            ? (query.isFetching
                                ? (locale === 'ar' ? 'جارٍ تحديث البيانات…' : 'Refreshing data…')
                                : (locale === 'ar' ? 'تحديث البيانات' : 'Refresh data'))
                            : route.key === 'backups'
                                ? (query.isFetching
                                    ? (locale === 'ar' ? 'جارٍ إعادة تحميل النسخ…' : 'Reloading backups…')
                                    : (locale === 'ar' ? 'إعادة تحميل النسخ' : 'Reload backups'))
                                : route.key === 'site-operations'
                                    ? (query.isFetching
                                        ? (locale === 'ar' ? 'جارٍ تحديث سجل العمليات…' : 'Refreshing operation history…')
                                        : text(commonText.refresh))
                                    : route.key === 'site-reliability'
                                        ? (query.isFetching
                                            ? (locale === 'ar' ? 'جارٍ تحديث مؤشرات الموثوقية…' : 'Refreshing reliability signals…')
                                            : text(commonText.refresh))
                                        : text(commonText.refresh)}
                    </button>
                    )}
                    {route.key === 'site-reliability' ? (
                        <SiteReliabilityOperationHistoryLinkControl context={context} />
                    ) : null}
                    {route.controls?.map((actionKey) => {
                        // Global execution actions have no operation ID; keep their
                        // inventory contracts while rendering only scoped row controls.
                        if (route.key === 'execution' && (actionKey === 'execution.retry' || actionKey === 'execution.cancel')) return null;
                        if (route.key === 'roles' && actionKey === 'roles.create') return null;
                        const canonicalOperation = AUTOMATION_PHASE_ACTION_OPERATIONS[actionKey];
                        return (
                            <span key={actionKey} data-canonical-operation={canonicalOperation}>
                                <ActionButton route={route} actionKey={actionKey} context={context} onAvailable={(contract) => setDialog({ key: actionKey, contract })} />
                            </span>
                        );
                    })}
                    {route.key === 'site-operations' ? (
                        <SiteOperationsExportControl rows={visibleRows}
                            locale={locale}
                            resetToken={siteOperationsExportResetToken}
                        />
                    ) : null}
                    {route.key === 'schedules' ? (
                        <button
                            type="button"
                            className="btn"
                            data-canonical-operation={SCHEDULE_CANCEL_EDIT_OPERATION_ID}
                            disabled={!dialog}
                            onClick={() => setDialog(null)}
                        >{locale === 'ar' ? 'إلغاء التعديل' : 'Cancel edit'}</button>
                    ) : null}
                </div>
            </section>
            {aiCenterMeta ? (
                <section className="panel" data-ai-center-metadata data-canonical-operation-state={aiCenterMeta.operation_id ?? ''} aria-label={locale === 'ar' ? 'بيانات مركز الذكاء الاصطناعي' : 'AI Center metadata'}>
                    <header className="panel-header">
                        <div><span className="workspace-kicker">METADATA</span><strong>{locale === 'ar' ? 'الحالة الموثوقة' : 'Authoritative state'}</strong></div>
                        <span className="tenant-badge" data-bidi="technical">{aiCenterMeta.locale ?? '—'}</span>
                    </header>
                    <dl className="contract-details">
                        <div><dt>{locale === 'ar' ? 'القوالب المتاحة' : 'Available prompts'}</dt><dd>{aiCenterMeta.available_prompts ?? collection.total}</dd></div>
                        <div><dt>{locale === 'ar' ? 'آخر استخداماتك' : 'Your recent usage'}</dt><dd>{aiCenterMeta.recent_usage_count ?? 0}</dd></div>
                        <div><dt>{locale === 'ar' ? 'المواقع المتاحة' : 'Available sites'}</dt><dd>{aiCenterSites.length}</dd></div>
                        <div><dt>{locale === 'ar' ? 'حالة الموافقة' : 'Approval state'}</dt><dd>{aiCenterMeta.approval?.status ?? (locale === 'ar' ? 'لا توجد' : 'None')}</dd></div>
                    </dl>
                </section>
            ) : null}
            {route.key === 'ai-center' ? (
                <AiCenterGenerateControl
                    context={context}
                    prompts={collection.rows
                        .map((row) => ({ key: String(row.key ?? row.stable_key ?? ''), title: String(row.title ?? row.key ?? row.stable_key ?? '') }))
                        .filter((prompt) => Boolean(prompt.key))}
                    sites={aiCenterSites}
                />
            ) : null}
            {(route.key === 'logs' || route.key === 'diagnostics') ? (
                <section className="panel" data-observability-scope="tenant-only" aria-label={locale === 'ar' ? 'نطاق السجلات والتشخيصات' : 'Observability scope'}>
                    <p>{route.key === 'logs'
                        ? (locale === 'ar' ? 'هذه سجلات عمليات الحساب المحفوظة في قاعدة البيانات، وليست ملفات سجلات Laravel أو أخطاء الخادم بالكامل.' : 'These are persisted tenant operation logs, not Laravel file logs or all infrastructure errors.')
                        : (locale === 'ar' ? 'تُعرض العمليات الفاشلة وأحداث التدقيق الخاصة بالحساب فقط؛ لا تتضمن هذه القائمة جميع مشكلات الخادم أو WordPress.' : 'Only tenant failed operations and audit events are shown; this is not a complete server or WordPress error log.')}</p>
                    {route.key === 'diagnostics' && observabilityMeta ? (
                        <p data-observability-counts>
                            {locale === 'ar' ? 'العمليات الفاشلة' : 'Failed operations'}: {observabilityMeta.failed_operations_count ?? 0}
                            {' · '}{locale === 'ar' ? 'أحداث التدقيق' : 'Audit events'}: {observabilityMeta.recent_audit_count ?? 0}
                            {observabilityMeta.scope === 'tenant_operations_and_audit' ? (
                                <>
                                    {' · '}{locale === 'ar' ? 'الإجمالي الفعلي للفشل' : 'Total failures'}: {observabilityMeta.failed_operations_total}
                                    {' · '}{locale === 'ar' ? 'إجمالي التدقيق' : 'Total audit'}: {observabilityMeta.audit_events_total}
                                    {observabilityMeta.failed_operations_truncated || observabilityMeta.recent_audit_truncated
                                        ? (locale === 'ar' ? ' · تعرض الصفحة أحدث 100 سجل من كل مصدر فقط' : ' · Showing the latest 100 per source only')
                                        : null}
                                    {observabilityMeta.empty_reason === 'no_tenant_events'
                                        ? (locale === 'ar' ? ' · لا توجد أحداث في هذين المصدرين؛ راجع صحة النظام للأعطال الأخرى' : ' · No events in these two sources; see System Health for other failures')
                                        : null}
                                </>
                            ) : null}
                        </p>
                    ) : null}
                    {route.key === 'logs' && logsMeta?.scope === 'tenant_operation_logs' ? (
                        <p data-observability-logs-evidence>
                            {locale === 'ar' ? 'سجلات عمليات الحساب' : 'Tenant operation logs'}: {logsMeta.tenant_records}
                            {' · '}{locale === 'ar' ? 'المطابقة للفلاتر' : 'Matching filters'}: {logsMeta.matching_records}
                            {' · '}{locale === 'ar' ? 'المعروضة' : 'Returned'}: {logsMeta.returned_records}
                            {logsMeta.truncated
                                ? (locale === 'ar' ? ' · تم عرض أحدث 500 سجل فقط' : ' · Only the latest 500 records are displayed')
                                : null}
                        </p>
                    ) : null}
                    <div className="toolbar-actions">
                        <Link to={tenantUrl(context.tenant.slug, '/system-health')}>{locale === 'ar' ? 'صحة النظام' : 'System Health'}</Link>
                        <Link to={tenantUrl(context.tenant.slug, '/runtime-inspector')}>{locale === 'ar' ? 'مفتش التشغيل' : 'Runtime Inspector'}</Link>
                    </div>
                </section>
            ) : null}
            <section className="panel data-panel">
                <header className="panel-header">
                    <div><span className="workspace-kicker">LIVE DATA</span><h2>{route.label[locale]}</h2></div>
                    <div className="toolbar-actions">
                        {route.key === 'email-history' ? (
                            <AppDataGridSortControl
                                sortDescending={appDataGridSortDescending}
                                locale={locale}
                                onToggle={(sortDescending) => {
                                    setAppDataGridSortDescending(sortDescending);
                                    setPage(1);
                                }}
                            />
                        ) : null}
                        <span className="count-badge">{collection.total}</span>
                    </div>
                </header>
                {route.key === 'explorer' ? (
                    <ContentExplorerSelectAllFilteredControl
                        canEdit={canEditExplorerContent}
                        selectedCount={explorerSelectedKeys.size}
                        total={collection.total}
                        onSelectAll={selectAllExplorerFiltered}
                        afterActions={
                            <ContentExplorerClearSelectionControl
                                canEdit={canEditExplorerContent}
                                selectedCount={explorerSelectedKeys.size}
                                onClear={() => setExplorerSelectedKeys(new Set())}
                            />
                        }
                    />
                ) : null}
                {visibleRows.length ? (
                    <DataTable
                        rows={visibleRows}
                        preferredColumns={route.key === 'logs' ? ['id', 'level', 'message', 'occurred_at'] : route.key === 'execution' ? ['id', 'type', 'status', 'progress', 'attempts', 'max_attempts', 'created_at', 'updated_at'] : undefined}
                        layout={route.key === 'sites' ? 'cards' : 'table'}
                        renderActions={route.key === 'execution' ? (row) => (
                            <ExecutionCenterRowActions context={context} row={row}
                                onReconcile={async () => {
                                    const refreshed = await query.refetch();
                                    if (refreshed.error) throw refreshed.error;
                                }} />
                        ) : route.key === 'site-operations' ? (row) => (
                            <SiteOperationsDetailsLinkControl context={context} row={row} />
                        ) : route.key === 'site-reliability' ? (row) => (
                            <SiteReliabilityConnectionLinkControl tenantSlug={context.tenant.slug} siteId={row.site_id} />
                        ) : route.key === 'explorer' ? (row) => {
                            const selectionKey = contentExplorerSelectionKey(row);
                            const selected = Boolean(selectionKey && explorerSelectedKeys.has(selectionKey));
                            return (
                            <span className="site-row-actions">
                                {canEditExplorerContent && selectionKey ? (
                                    <label className="row-selection-control">
                                        <input
                                            type="checkbox"
                                            aria-label={locale === 'ar' ? 'تحديد ' + String(row.title ?? '') : 'Select ' + String(row.title ?? '')}
                                            checked={selected}
                                            onChange={(event) => {
                                                const checked = event.currentTarget.checked;
                                                setExplorerSelectedKeys((current) => {
                                                    const next = new Set(current);
                                                    if (checked) next.add(selectionKey); else next.delete(selectionKey);
                                                    return next;
                                                });
                                            }}
                                        />
                                    </label>
                                ) : null}
                                <button
                                    type="button"
                                    className="btn compact"
                                    onClick={() => setExplorerPreview(row)}
                                >{locale === 'ar' ? 'معاينة' : 'Preview'}</button>
                                <span aria-hidden="true"> · </span>
                                <ContentExplorerWordPressLinkControl value={row.link} />
                            </span>
                            );
                        } : route.key === 'taxonomy' ? (row) => (
                            <GlobalTaxonomySiteLinksControl context={context} siteId={row.site_id ?? row.siteId} />
                        ) : route.key === 'sites' ? (row) => (
                            <span className="site-row-actions">
                                <SitesInterpolatedLinksControl tenantSlug={context.tenant.slug} siteId={row.id} />
                                <span aria-hidden="true"> · </span>
                                <SitesSiteUrlLinkControl value={row.site_url ?? row.url} />
                            </span>
                        ) : route.key === 'posts' ? (row) => (
                            <PostsWordPressLinkControl value={row.link} />
                        ) : route.key === 'pages' ? (row) => (
                            <span className="site-row-actions">
                                <PagesInterpolatedLinksControl
                                    tenantSlug={context.tenant.slug}
                                    siteId={row.site_id}
                                    wordpressId={row.wordpress_id}
                                    siteName={row.site_name}
                                />
                                <span aria-hidden="true"> · </span>
                                <PagesWordPressLinkControl value={row.link} />
                            </span>
                        ) : route.key === 'media' && endpoint ? (row) => (
                            <span className="site-row-actions">
                                <GlobalMediaInterpolatedLinksControl
                                    tenantSlug={context.tenant.slug}
                                    siteId={row.site_id}
                                />
                                <span aria-hidden="true"> · </span>
                                <MediaManagerRowSourceUrlControl value={row.source_url}>
                                    <MediaManagerEditingSourceUrlControl value={row.source_url}>
                                        <ContentExplorerMediaSourceUrlControl value={row.source_url} />
                                    </MediaManagerEditingSourceUrlControl>
                                </MediaManagerRowSourceUrlControl>
                                <MediaAltSuggestionControl context={context} endpoint={endpoint} row={row} />
                                <MediaOptimizationEditorControl
                                    context={context}
                                    endpoint={endpoint}
                                    row={row}
                                    onReconcile={async () => {
                                        const refreshed = await query.refetch();
                                        if (refreshed.error) throw refreshed.error;
                                    }}
                                />
                                <MediaDeleteControl
                                    context={context}
                                    endpoint={endpoint}
                                    row={row}
                                    onReconcile={async () => {
                                        const refreshed = await query.refetch();
                                        if (refreshed.error) throw refreshed.error;
                                    }}
                                />
                            </span>
                        ) : route.key === 'notifications' ? (row) => (
                            <NotificationOpenControl context={context} row={row} />
                        ) : undefined}
                    />
                ) : <div className="empty-state"><strong>{text(commonText.empty)}</strong><p>{route.key === 'diagnostics'
                    ? (locale === 'ar' ? 'لم تُرجع واجهة Laravel عمليات فاشلة أو أحداث تدقيق لهذا الحساب. تحقق من صحة النظام ومفتش التشغيل لمشكلات الاستضافة.' : 'Laravel returned no failed operations or audit events for this tenant. Check System Health and Runtime Inspector for host incidents.')
                    : route.key === 'execution'
                        ? (locale === 'ar'
                            ? 'لا توجد عمليات مطابقة لهذا الحساب والفلاتر الحالية. قد لا تكون هناك عمليات مسجلة؛ لا يتم إنشاء بيانات تجريبية.'
                            : 'No executions match this tenant and its filters. There may be no recorded work; sample rows are never created.')
                    : route.key === 'logs'
                        ? logsMeta?.empty_reason === 'filter_matched_none' || (!logsMeta && search.trim())
                            ? (locale === 'ar' ? 'توجد سجلات عمليات لهذا الحساب، لكن الفلاتر الحالية لم تُطابق أي سجل.' : 'Tenant operation logs exist, but the current filters matched none.')
                            : (locale === 'ar' ? 'لا توجد سجلات عمليات محفوظة لهذا الحساب. لا يعني ذلك خلو الخادم من الأخطاء.' : 'No tenant operation logs are persisted. This does not prove the server has no errors.')
                        : (locale === 'ar' ? 'لا يتم إنشاء صفوف تجريبية عندما يعيد الخادم نتيجة فارغة.' : 'No sample rows are synthesized when the server returns an empty result.')}</p></div>}
                {route.key === 'explorer'
                    ? <ContentExplorerPagination page={collection.page} lastPage={collection.lastPage} onPage={setPage}
                        total={collection.authoritativeTotal} pageSize={explorerPageSize} returnedCount={collection.rows.length}
                        onPageSizeChange={(size) => {
                            setPage(1);
                            setExplorerSelectedKeys(new Set());
                            setExplorerPageSize(size);
                        }} />
                    : <Pagination page={collection.page} lastPage={collection.lastPage} onPage={route.key === 'sites' ? (next) => navigateSites(next, sitesPageSize, sitesFilter) : route.key === 'execution' ? (next) => navigateExecution({ ...executionState, page: next }) : setPage}
                        total={collection.authoritativeTotal}
                        pageSize={route.key === 'application-users' ? applicationUsersPageSize : route.key === 'execution' ? executionState.pageSize : route.key === 'email-history' ? emailHistoryPageSize : route.key === 'approvals' ? approvalPageSize : route.key === 'evidence' ? evidencePageSize : route.key === 'email-schedules' ? emailSchedulesPageSize : route.key === 'notifications' ? notificationsPageSize : route.key === 'logs' ? logsPageSize : route.key === 'sites' ? sitesPageSize : undefined}
                        returnedCount={collection.rows.length}
                        onPageSizeChange={route.key === 'application-users' ? (size) => {
                            setPage(1);
                            setApplicationUsersPageSize(size);
                        } : route.key === 'execution' ? (size) => {
                            if (EXECUTION_PAGE_SIZES.includes(size as typeof EXECUTION_PAGE_SIZES[number])) {
                                navigateExecution({ ...executionState, page: 1, pageSize: size });
                            }
                        } : route.key === 'email-history' ? (size) => {
                            setPage(1);
                            setEmailHistoryPageSize(size);
                        } : route.key === 'notifications' ? (size) => {
                            setPage(1);
                            setNotificationsPageSize(size);
                        } : route.key === 'email-schedules' ? (size) => {
                            setPage(1);
                            setEmailSchedulesPageSize(size);
                        } : route.key === 'logs' ? (size) => {
                            setPage(1);
                            setLogsPageSize(size);
                        } : route.key === 'evidence' ? (size) => {
                            setPage(1);
                            setEvidencePageSize(size);
                        } : route.key === 'approvals' ? (size) => {
                            setPage(1);
                            setApprovalPageSize(size);
                        } : route.key === 'sites' ? (size) => navigateSites(1, size, sitesFilter) : undefined}
                        previousOperationAliasId={route.key === 'comments' ? COMMENTS_PREVIOUS_PAGE_OPERATION_ID : route.key === 'wp-users' ? WORDPRESS_USERS_PREVIOUS_OPERATION_ID : undefined}
                        previousOperationId={readOperations?.previous ?? (route.key === 'email-history' ? APP_DATA_GRID_PREVIOUS_PAGE_OPERATION_ID : undefined)} />}
            </section>
            {route.key === 'explorer' && explorerPreview ? (
                <ContentExplorerPreviewPanel
                    row={explorerPreview}
                    onClose={() => setExplorerPreview(null)}
                />
            ) : null}
            <ActionDialog
                open={Boolean(dialog)}
                actionKey={dialog?.key ?? ''}
                contract={dialog?.contract ?? null}
                onClose={closeActionDialog}
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
    const location = useLocation();
    const related = useMemo(() => {
        if (route.key === 'welcome' || route.key === 'system-overview' || route.key === 'workspace') return ['overview', 'content', 'seo', 'ai', 'operations', 'reports', 'system'];
        if (route.key === 'content-hub') return ['content'];
        if (route.key === 'operations') return ['operations'];
        return [route.group];
    }, [route]);

    return (
        <div className="workspace-stack">
            <section className="hero-panel" data-canonical-operation={route.key === 'content-hub' ? CONTENT_WORKSPACE_LOAD_OPERATION_ID : undefined}><div><span className="workspace-kicker">WORKSPACE</span><h2>{route.label[locale]}</h2><p>{route.description[locale]}</p></div><span className="tenant-badge">{context.tenant.name}</span></section>
            {route.key === 'content-hub' ? (
                <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'تنقل مساحة المحتوى' : 'Content workspace navigation'}>
                    <div className="toolbar-actions">
                        <Link
                            className="btn primary"
                            to={contentHubWorkspaceUrl(tenantSlug, '/module/sync', location.search)}
                            data-canonical-operation={CONTENT_HUB_WORKSPACE_URL_OPERATION_ID}
                        >
                            {locale === 'ar' ? 'فتح المزامنة' : 'Open synchronization'}
                        </Link>
                    </div>
                </nav>
            ) : null}
            <section className="workspace-card-grid">
                {workspaceRoutes.filter((candidate) => !candidate.hidden && related.includes(candidate.group) && candidate.key !== route.key).map((candidate) => {
                    if (route.key === 'operations' && candidate.key === 'site-operations') {
                        return <OperationsHubSiteOperationsControl key={candidate.key} context={context} />;
                    }

                    const state = resolveCapability(context, candidate);
                    const sourceWorkspacePath = route.key === 'content-hub' && isContentHubWorkspacePath(candidate.path);
                    const destination = sourceWorkspacePath
                        ? contentHubWorkspaceUrl(tenantSlug, candidate.path, location.search)
                        : tenantUrl(tenantSlug, candidate.path);
                    return (
                        <Link
                            className="workspace-card"
                            key={candidate.key}
                            to={destination}
                            data-canonical-operation={sourceWorkspacePath ? CONTENT_HUB_WORKSPACE_URL_OPERATION_ID : undefined}
                        >
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
    if (route.key === 'wp-users') return <><GlobalUsersFullManagementControl context={context} route={route} /><ResourceContent context={context} route={route} /></>;
    if (route.key === 'explorer') return <><ContentExplorerBackToSitesControl context={context} /><ResourceContent context={context} route={route} /></>;
    if (route.key === 'account-profile') return <><AccountProfileEmailSettingsLinkControl context={context} /><ResourceContent context={context} route={route} /></>;
    if (route.key === 'account-billing') return <><ResourceContent context={context} route={route} /><BillingHistoryPanel context={context} /></>;
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
