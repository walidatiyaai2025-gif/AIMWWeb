import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useLocation, useParams } from 'react-router-dom';
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
import { CONTENT_HUB_WORKSPACE_URL_OPERATION_ID, contentHubWorkspaceUrl, isContentHubWorkspacePath } from './content-hub-workspace-url';
import { AiCenterGenerateControl } from './ai-center-generate-control';
import { AdminBillingSupportClearSearchControl } from './admin-billing-support-clear-search-control';
import { APP_DATA_GRID_PREVIOUS_PAGE_OPERATION_ID } from './app-data-grid-previous-page-control';
import { AppDataGridSortControl } from './app-data-grid-sort-control';
import { AppDataGridClearFiltersControl } from './app-data-grid-clear-filters-control';
import { AppSearchBox } from './app-search-box';
import { AccountProfileEmailSettingsLinkControl } from './account-profile-email-settings-link-control';
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
import { DashboardSitesNavigationControl } from './dashboard-sites-navigation-control';
import { DashboardSystemHealthNavigationControl } from './dashboard-system-health-navigation-control';
import { DashboardSitesQuickAccessControl } from './dashboard-sites-quick-access-control';
import { DashboardMediaNavigationControl } from './dashboard-media-navigation-control';
import { LiveSystemStatusRefreshControl } from './live-system-status-refresh-control';
import { EMAIL_HISTORY_CLEAR_FILTERS_OPERATION_ID, EmailHistoryFiltersControl, type EmailHistoryFilterState } from './email-history-filters-control';
import { GlobalUsersFullManagementControl } from './global-users-full-management-control';
import { MediaDeleteControl } from './media-delete-control';
import { authoritativeGlobalMediaEndpoint, globalMediaResetState, GlobalMediaResetControl } from './global-media-reset-control';
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
import { SiteReliabilityFiltersControl, siteReliabilityDefaultFilters, type SiteReliabilityFilterState } from './site-reliability-filters-control';
import { SitesSiteUrlLinkControl } from './sites-site-url-link-control';
import { SitesInterpolatedLinksControl } from './sites-interpolated-links-control';
import { SitesSystemHealthLinkControl } from './sites-system-health-link-control';
import { runAuthoritativeSiteOperationsReload, SITE_OPERATIONS_RELOAD_OPERATION_ID } from './site-operations-reload-control';
import { runAuthoritativeSiteReliabilityReload, SITE_RELIABILITY_RELOAD_OPERATION_ID } from './site-reliability-reload-control';
import { SiteReliabilityOperationHistoryLinkControl } from './site-reliability-operation-history-link-control';

const SITES_RELOAD_OPERATION_ID = 'AIMW-SYNC-A9E956A4DA';
const SITES_SHOW_ALL_OPERATION_ID = 'AIMW-CONT-C178278FCB';
const SITES_SHOW_CONNECTED_OPERATION_ID = 'AIMW-CONT-E346398EAD';
const SITES_SHOW_REVIEW_OPERATION_ID = 'AIMW-CONT-CA17F05024';
export const COMMENTS_SEARCH_OPERATION_ID = 'AIMW-SYNC-4E3C0156FC';
export const COMMENTS_REFRESH_CLICK_OPERATION_ID = 'AIMW-SYNC-D5CE5A213B';
export const WORDPRESS_USERS_SEARCH_OPERATION_ID = 'AIMW-SYNC-E6888B0B4D';
export const AI_CENTER_METADATA_REFRESH_OPERATION_ID = 'AIMW-AI-953A6C0D98';
export const NOTIFICATION_INBOX_RELOAD_OPERATION_ID = 'AIMW-EMAI-12A6FEB2FF';

const COLLECTION_READ_OPERATIONS: Partial<Record<string, { load?: string; previous?: string; refresh?: string }>> = {
    comments: { load: 'AIMW-SYNC-12F15A0A80', previous: 'AIMW-SYNC-CB01197D47', refresh: 'AIMW-SYNC-DBD736FACC' },
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
    current_page?: number;
    page?: number;
    last_page?: number;
    lastPage?: number;
    meta?: ({ total?: number; current_page?: number; last_page?: number } & AiCenterMetadata);
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
    if (query.error) return <QueryError error={query.error} retry={() => query.refetch()} />;

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
        <div className="workspace-stack">
            <section className="hero-panel">
                <div><span className="workspace-kicker">LIVE TENANT DATA</span><h2>{locale === 'ar' ? 'ملخص التشغيل' : 'Operational summary'}</h2><p>{locale === 'ar' ? 'تُعرض المؤشرات فقط عندما يعيدها خادم Laravel للحساب الحالي.' : 'Metrics render only when the Laravel backend returns them for the active tenant.'}</p></div>
                <LiveSystemStatusRefreshControl busy={query.isFetching} onClick={() => query.refetch()} />
                <DashboardSitesNavigationControl context={context} />
                <DashboardSystemHealthNavigationControl context={context} />
                <DashboardSitesQuickAccessControl context={context} />
                <DashboardMediaNavigationControl context={context} />
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
                    {locale === 'ar' ? 'لم يرسل عقد لوحة التحكم بيانات قابلة للعرض. لن تستبدل الواجهة ذلك بأرقام تجريبية.' : 'The dashboard contract returned no renderable metrics. The frontend will not replace them with demo numbers.'}
                </StatePanel>
            )}
        </div>
    );
}

function QueryError({ error, retry }: { error: unknown; retry: () => void }) {
    const { locale, text } = useLocale();
    const apiError = error instanceof ApiError ? error : null;
    return (
        <StatePanel
            tone="danger"
            title={locale === 'ar' ? 'فشل تحميل البيانات' : 'Data could not be loaded'}
            onRetry={retry}
            retryText={text(commonText.retry)}
        >
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
        site_disconnected: { en: 'Site disconnected', ar: 'الموقع غير متصل' },
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
    const queryClient = useQueryClient();
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [mediaResetState, setMediaResetState] = useState(() => globalMediaResetState());
    const [appDataGridSortDescending, setAppDataGridSortDescending] = useState(false);
    const applicationUsersSearchInFlight = useRef(false);
    const [sitesFilter, setSitesFilter] = useState<'all' | 'connected' | 'review'>('all');
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
    const [taxonomyType, setTaxonomyType] = useState<'category' | 'post_tag'>('category');

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
        ? (authoritativeGlobalMediaEndpoint(context) ?? undefined)
        : routeEndpoint;
    const readOperations = COLLECTION_READ_OPERATIONS[route.key];
    const canEditExplorerContent = context.permissions.includes('*') || context.permissions.includes('content.edit');

    const selectAllExplorerFiltered = async (): Promise<void> => {
        if (route.key !== 'explorer' || !canEditExplorerContent) return;
        const keys = await loadAllFilteredExplorerKeys({ context, endpoint, search });
        setExplorerSelectedKeys(keys);
    };

    const taxonomyFilter: Record<string, string> = route.key === 'taxonomy' ? { taxonomy: taxonomyType } : {};
    const emailHistoryQueryFilters: Record<string, string> = route.key === 'email-history' ? {
        ...(emailHistoryFilters.siteId ? { site_id: emailHistoryFilters.siteId } : {}),
        ...(emailHistoryFilters.status ? { status: emailHistoryFilters.status } : {}),
        ...(emailHistoryFilters.correlation.trim() ? { correlation: emailHistoryFilters.correlation.trim() } : {}),
        sort_desc: appDataGridSortDescending ? '1' : '0',
    } : {};
    const emailHistoryActiveFilterCount = route.key === 'email-history'
        ? [emailHistoryFilters.siteId, emailHistoryFilters.status, emailHistoryFilters.correlation.trim()].filter(Boolean).length
        : 0;
    const siteReliabilityQueryFilters: Record<string, string> = route.key === 'site-reliability' ? {
        period_days: siteReliabilityFilters.periodDays,
        minimum_operations: siteReliabilityFilters.minimumOperations,
    } : {};
    const mediaQueryFilters: Record<string, string> = {};
    if (route.key === 'media') {
        mediaQueryFilters.per_page = String(mediaResetState.pageSize);
        if (mediaResetState.mediaType !== 'all') {
            mediaQueryFilters.mime_type = mediaResetState.mediaType;
        }
    }
    const resourceFilters = { ...taxonomyFilter, ...emailHistoryQueryFilters, ...siteReliabilityQueryFilters, ...mediaQueryFilters };
    const query = useQuery(route.key === 'email-history' ? {
        queryKey: ['workspace', context.tenant.slug, route.key, endpoint, page, search, taxonomyType, emailHistoryFilters.siteId, emailHistoryFilters.status, emailHistoryFilters.correlation, appDataGridSortDescending],
        queryFn: () => apiRequest<unknown>(endpointWithQuery(endpoint!, page, search, resourceFilters)),
        enabled: state.state === 'enabled' && Boolean(endpoint),
    } : route.key === 'site-reliability' ? {
        queryKey: ['workspace', context.tenant.slug, route.key, endpoint, page, search, siteReliabilityFilters.periodDays, siteReliabilityFilters.minimumOperations],
        queryFn: () => apiRequest<unknown>(endpointWithQuery(endpoint!, page, search, siteReliabilityQueryFilters)),
        enabled: state.state === 'enabled' && Boolean(endpoint),
    } : route.key === 'media' ? {
        queryKey: ['workspace', context.tenant.slug, route.key, endpoint, page, search, mediaResetState.mediaType, mediaResetState.pageSize],
        queryFn: () => apiRequest<unknown>(endpointWithQuery(endpoint!, page, search, mediaQueryFilters)),
        enabled: state.state === 'enabled' && Boolean(endpoint),
    } : {
        queryKey: ['workspace', context.tenant.slug, route.key, endpoint, page, search, taxonomyType],
        queryFn: () => apiRequest<unknown>(endpointWithQuery(endpoint!, page, search, taxonomyFilter)),
        enabled: state.state === 'enabled' && Boolean(endpoint),
    });

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
                queryKey: ['workspace', context.tenant.slug, route.key, endpoint, 1, nextSearch, taxonomyType],
                queryFn: () => apiRequest<unknown>(endpointWithQuery(endpoint, 1, nextSearch)),
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
            queryKey: ['workspace', context.tenant.slug, route.key, endpoint, 1, ''],
            queryFn: () => apiRequest<unknown>(endpointWithQuery(endpoint, 1, '')),
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

    const resetGlobalMediaFilters = async (): Promise<void> => {
        if (route.key !== 'media' || !endpoint || query.isFetching) return;

        const reset = globalMediaResetState();
        setSearchInput(reset.search);
        setSearch(reset.search);
        setPage(reset.page);
        setMediaResetState(reset);

        await queryClient.fetchQuery({
            queryKey: ['workspace', context.tenant.slug, route.key, endpoint, reset.page, reset.search, taxonomyType, reset.mediaType, reset.pageSize],
            queryFn: () => apiRequest<unknown>(endpointWithQuery(endpoint, reset.page, reset.search, {
                per_page: String(reset.pageSize),
            })),
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
    const visibleRows = route.key === 'sites'
        ? (sitesFilter === 'connected'
            ? collection.rows.filter(isSiteConnectedRow)
            : sitesFilter === 'review'
                ? collection.rows.filter(isSiteReviewRow)
                : collection.rows)
        : route.key === 'site-operations'
            ? filterSiteOperationsRows(collection.rows, search, siteOperationsFilters)
            : collection.rows;
    const connectedSiteCount = route.key === 'sites' ? collection.rows.filter(isSiteConnectedRow).length : 0;
    const reviewSiteCount = route.key === 'sites' ? collection.rows.filter(isSiteReviewRow).length : 0;
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
        <div className="workspace-stack">
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
                    <GlobalMediaResetControl
                        locale={locale}
                        busy={query.isFetching}
                        available={Boolean(endpoint)}
                        onReset={() => { void resetGlobalMediaFilters(); }}
                    />
                ) : null}
                <form className="search-form" onSubmit={(event) => {
                    event.preventDefault();
                    if (route.key === 'application-users') {
                        void searchApplicationUsers().catch(() => undefined);
                        return;
                    }
                    setPage(1); setSearch(searchInput); if (route.key === 'explorer') setExplorerSelectedKeys(new Set());
                }} role="search">
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
                    {route.key === 'application-users' ? (
                        <ApplicationUsersSearchControl
                            busy={query.isFetching || applicationUsersSearchInFlight.current}
                            locale={locale}
                            authorized={applicationUsersSearchAuthorized}
                        />
                    ) : (
                        <button
                            type="submit"
                            className="btn"
                            data-canonical-operation={route.key === 'wp-users'
                                ? WORDPRESS_USERS_SEARCH_OPERATION_ID
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
                    {route.key === 'admin-billing-support' ? (
                        <AdminBillingSupportClearSearchControl
                            busy={query.isFetching}
                            locale={locale}
                            available={Boolean(endpoint)}
                            onClearRequested={clearAdminBillingSupportSearch}
                        />
                    ) : null}
                </form>
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
                            onClick={() => setSitesFilter('all')}
                        >{locale === 'ar' ? 'الكل' : 'All'}</button>
                    ) : null}
                    {route.key === 'sites' ? (
                        <button
                            type="button"
                            className="btn"
                            data-canonical-operation={SITES_SHOW_CONNECTED_OPERATION_ID}
                            aria-pressed={sitesFilter === 'connected'}
                            onClick={() => setSitesFilter('connected')}
                        >{locale === 'ar' ? 'متصل' : 'Connected'} <b>{connectedSiteCount}</b></button>
                    ) : null}
                    {route.key === 'sites' ? (
                        <button
                            type="button"
                            className="btn"
                            data-canonical-operation={SITES_SHOW_REVIEW_OPERATION_ID}
                            aria-pressed={sitesFilter === 'review'}
                            onClick={() => setSitesFilter('review')}
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
                    <button
                        type="button"
                        className="btn"
                        data-canonical-operation={refreshOperationId}
                        data-canonical-load-operation={readOperations?.load}
                        data-canonical-refresh-operation={readOperations?.refresh}
                        disabled={(route.key === 'comments' || route.key === 'ai-center' || route.key === 'backups' || route.key === 'site-operations' || route.key === 'site-reliability') && query.isFetching}
                        aria-busy={(route.key === 'comments' || route.key === 'ai-center' || route.key === 'backups' || route.key === 'site-operations' || route.key === 'site-reliability') && query.isFetching ? 'true' : 'false'}
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
                    {route.key === 'site-reliability' ? (
                        <SiteReliabilityOperationHistoryLinkControl context={context} />
                    ) : null}
                    {route.controls?.map((actionKey) => {
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
                        <span className="count-badge">{route.key === 'sites' && sitesFilter !== 'all' ? visibleRows.length : collection.total}</span>
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
                        renderActions={route.key === 'site-operations' ? (row) => (
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
                ) : <div className="empty-state"><strong>{text(commonText.empty)}</strong><p>{locale === 'ar' ? 'لا يتم إنشاء صفوف تجريبية عندما يعيد الخادم نتيجة فارغة.' : 'No sample rows are synthesized when the server returns an empty result.'}</p></div>}
                {route.key === 'explorer'
                    ? <ContentExplorerPagination page={collection.page} lastPage={collection.lastPage} onPage={setPage} />
                    : <Pagination page={collection.page} lastPage={collection.lastPage} onPage={setPage} previousOperationId={readOperations?.previous ?? (route.key === 'email-history' ? APP_DATA_GRID_PREVIOUS_PAGE_OPERATION_ID : undefined)} />}
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
