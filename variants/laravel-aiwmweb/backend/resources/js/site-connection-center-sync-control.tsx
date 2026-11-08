import React, { useCallback, useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { ApiError, apiRequest, tenantUrl, type FrontendContext } from './core';
import { Pagination } from './components';
import { useLocale } from './i18n';
import { safeExternalSiteUrl } from './site-details-site-url-control';
import { formatSiteSyncDiagnostic } from './site-details-synchronize-control';

export const SITE_CONNECTION_CENTER_SYNC_OPERATION_ID = 'AIMW-BILL-3762C05261';
export const SITE_CONNECTION_CENTER_COPY_DIAGNOSTICS_OPERATION_ID = 'AIMW-BILL-D4E1DA62D9';
export const SITE_CONNECTION_CENTER_RETEST_OPERATION_ID = 'AIMW-BILL-E747D0E843';
export const SITE_CONNECTION_CENTER_SITE_URL_OPERATION_ID = 'AIMW-CONT-63EDB4BA6F';
export const SITE_CONNECTION_CENTER_BACK_TO_SITES_OPERATION_ID = 'AIMW-CONT-8C19E4E3B2';
export const SITE_CONNECTION_CENTER_DYNAMIC_LINKS_OPERATION_ID = 'AIMW-CONT-DF6FFA9D87';

type ConnectionSnapshot = {
    site: {
        id: number;
        name: string;
        url?: unknown;
        connection_status?: string | null;
        wordpress_version?: string | null;
        language_code?: string | null;
        last_verified_at?: string | null;
    };
    connection: string;
    sync_health?: { state: 'not_probed' | 'succeeded' | 'failed'; last_attempt_at?: string | null };
    last_verified_at?: string | null;
    metadata?: Partial<Record<'wordpress_version' | 'language_code', { state: string; checked_at?: string | null }>>;
};

type OperationHistoryItem = {
    id?: number;
    operation: string;
    status: string;
    message: string;
    correlation_id?: string | null;
    details?: {
        sync_run_id?: number;
        failure_code?: string;
        failure_category?: string;
        failure_message?: string;
    } | null;
    affected_records?: number | null;
    started_at?: string | null;
    completed_at?: string | null;
};

type OperationHistoryPayload = { items: OperationHistoryItem[]; total?: number; page?: number; last_page?: number; per_page?: number };
type HistoryPaging = { total: number; page: number; lastPage: number; perPage: number };
const SYNC_FAILURE_AR: Record<string, string> = {
    authentication: 'فشل التحقق من اتصال WordPress. راجع بيانات الربط وتوقيع الطلبات.',
    rate_limit: 'تم تجاوز حدود الطلبات أو الحصة. راجع الحدود قبل إعادة المحاولة.',
    protocol: 'بروتوكول الربط غير متوافق. تحقق من إصدار الإضافة والتطبيق.',
    transport: 'تعذر الوصول إلى WordPress. تحقق من الشبكة وشهادة الاتصال.',
    remote_service: 'أعاد WordPress خطأ من الخادم. تحقق من حالته قبل إعادة المحاولة.',
    storage: 'حدث خطأ في قاعدة البيانات أو مساحة التخزين. تحقق من حالة الخادم.',
    unknown: 'فشلت المزامنة لسبب غير مصنف. افتح تفاصيل العملية لمزيد من التشخيص.',
};


type SyncRun = { id: number; status: string; processed?: number; failure?: string | null; diagnostic_code?: string | null };

export type SiteConnectionCenterDynamicLinks = {
    dashboard: string;
    applicationPassword: string | null;
    localContent: string;
    execution: string;
};

export function siteConnectionCenterDynamicLinks(
    tenantSlug: string,
    routeSiteId: string | number | undefined,
    site: ConnectionSnapshot['site'] | null | undefined,
): SiteConnectionCenterDynamicLinks | null {
    const rawSiteId = String(routeSiteId ?? '').trim();
    if (!/^[1-9]\d*$/.test(rawSiteId) || !tenantSlug.trim() || /[\\/]/.test(tenantSlug)) return null;
    if (!site || Number(site.id) !== Number(rawSiteId)) return null;

    const encodedSiteId = encodeURIComponent(rawSiteId);
    const siteUrl = safeExternalSiteUrl(site.url);
    let applicationPassword: string | null = null;

    if (siteUrl) {
        try {
            const profile = new URL(siteUrl);
            profile.pathname = `${profile.pathname.replace(/\/+$/, '')}/wp-admin/profile.php`;
            profile.search = '';
            profile.hash = '';
            applicationPassword = profile.toString();
        } catch {
            applicationPassword = null;
        }
    }

    return {
        dashboard: tenantUrl(tenantSlug, `/sites/${encodedSiteId}`),
        applicationPassword,
        localContent: `${tenantUrl(tenantSlug, '/explorer')}?site=${encodedSiteId}`,
        execution: `${tenantUrl(tenantSlug, '/module/execution')}?siteId=${encodedSiteId}`,
    };
}


// Clipboard reports may be forwarded outside this tenant. Do not copy raw
// upstream messages, signed URLs, credentials, SQL or untrusted extra fields.
const DIAGNOSTIC_METADATA_STATES = new Set([
    'verified', 'not_probed', 'not_supported', 'permission_denied', 'temporarily_unavailable',
]);
const DIAGNOSTIC_FAILURE_GUIDANCE: Record<string, string> = {
    authentication: 'Check WordPress authentication and Connector permissions.',
    rate_limit: 'Check provider rate limits before retrying.',
    protocol: 'Check Laravel and Connector protocol compatibility.',
    transport: 'Check WordPress network and TLS connectivity.',
    remote_service: 'Check WordPress server availability.',
    storage: 'Check database and storage health.',
    unknown: 'Review authorized operation details.',
};
function copiedSiteLabel(value: unknown): string {
    if (typeof value !== 'string') return '[WITHHELD]';
    const label = value.trim();
    if (!label || label.length > 120 || /[\u0000-\u001f\u007f]/.test(label)
        || /(?:https?:\/\/|Bearer\s|(?:password|passwd|token|secret|api[_-]?key)\s*[:=])/i.test(label)) return '[WITHHELD]';
    return label;
}
function copiedSiteOrigin(value: unknown): string {
    if (typeof value !== 'string' || value.trim() !== value || /[\u0000-\u001f\u007f]/.test(value)) return 'unavailable';
    try {
        const url = new URL(value);
        // Origin drops all userinfo, path, query, hash and authorization hints.
        return (url.protocol === 'https:' || url.protocol === 'http:') && url.hostname
            ? url.origin : 'unavailable';
    } catch {
        return 'unavailable';
    }
}
function copiedTimestamp(value: unknown): string {
    if (typeof value !== 'string' || value.length > 40) return 'not verified';
    return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/.test(value)
        ? value : 'not verified';
}
function copiedMetadataState(value: unknown): string {
    return typeof value === 'string' && DIAGNOSTIC_METADATA_STATES.has(value) ? value : 'not_probed';
}
function copiedOperationName(value: unknown): string {
    return typeof value === 'string' && /^[a-z][a-z0-9_-]{0,79}$/.test(value) ? value : 'operation_withheld';
}
function copiedOperationOutcome(value: unknown): string {
    if (typeof value !== 'string') return 'UNKNOWN';
    const status = value.toLowerCase();
    return ['succeeded', 'failed', 'cancelled', 'cancel_requested', 'queued', 'running'].includes(status)
        ? (status === 'succeeded' ? 'OK' : status.toUpperCase()) : 'UNKNOWN';
}
function copiedOperationSummary(item: OperationHistoryItem, outcome: string): string {
    if (outcome === 'OK') return item.operation === 'synchronization'
        ? 'Synchronization completed.' : 'Operation completed.';
    if (outcome !== 'FAILED') return 'See authorized operation details.';
    const category = item.details?.failure_category;
    if (typeof category !== 'string' || !Object.prototype.hasOwnProperty.call(DIAGNOSTIC_FAILURE_GUIDANCE, category))
        return 'Failed; review authorized operation details.';
    const expected = 'WP_SYNC_' + category.toUpperCase();
    const code = item.details?.failure_code === expected ? ' [' + expected + ']' : '';
    return 'Failed' + code + ': ' + DIAGNOSTIC_FAILURE_GUIDANCE[category];
}
export function formatSiteDiagnosticsReport(connection: ConnectionSnapshot, history: OperationHistoryItem[]): string {
    const site = connection.site as ConnectionSnapshot['site'] & { wordpress_version?: string | null; language_code?: string | null; last_verified_at?: string | null };
    const version = typeof site.wordpress_version === 'string'
        && /^[0-9]+(?:\.[0-9]+){1,3}(?:[-+][a-zA-Z0-9.-]+)?$/.test(site.wordpress_version)
        ? site.wordpress_version : null;
    const language = typeof site.language_code === 'string'
        && /^[a-z]{2,3}(?:_[A-Z]{2})?$/.test(site.language_code) ? site.language_code : null;
    const state = site.connection_status;
    const status = typeof state === 'string'
        && /^(?:connected|disconnected|degraded|auth_failed|capability_disabled|connector_disabled|temporarily_unavailable|unsupported|unknown)$/i.test(state)
        ? state : 'unknown';
    const lines = [
        'AI WordPress Manager - Site Diagnostics',
        'Site: ' + copiedSiteLabel(site.name),
        'URL: ' + copiedSiteOrigin(site.url),
        'Status: ' + status,
        'WordPress: ' + (version ?? 'unavailable (' + copiedMetadataState(connection.metadata?.wordpress_version?.state) + ')'),
        'Language: ' + (language ?? 'unavailable (' + copiedMetadataState(connection.metadata?.language_code?.state) + ')'),
        'WordPress metadata checked: ' + copiedTimestamp(connection.metadata?.wordpress_version?.checked_at),
        'Language metadata checked: ' + copiedTimestamp(connection.metadata?.language_code?.checked_at),
        'Last test: ' + copiedTimestamp(connection.last_verified_at ?? site.last_verified_at),
        '',
        'Recent operations:',
    ];
    for (const item of history.slice(0, 20)) {
        const outcome = copiedOperationOutcome(item.status);
        lines.push('[' + copiedTimestamp(item.started_at) + '] ' + copiedOperationName(item.operation)
            + ' ' + outcome + ' - ' + copiedOperationSummary(item, outcome));
    }
    return lines.join('\n');
}

export function siteConnectionCenterEndpoints(tenantSlug: string, siteId: string | number) {
    const raw = String(siteId).trim();
    if (!/^[1-9]\d*$/.test(raw) || !tenantSlug.trim() || /[\\/]/.test(tenantSlug)) return null;

    const tenant = encodeURIComponent(tenantSlug);
    const site = encodeURIComponent(raw);

    return {
        connection: `/api/tenants/${tenant}/sites/${site}/connection`,
        operations: `/api/tenants/${tenant}/sites/${site}/operations?take=100`,
        pagedOperations: (page: number, perPage: number, status: string, operation: string) => {
            const params = new URLSearchParams({ page: String(page), per_page: String(perPage) });
            if (status) params.set('status', status);
            if (operation) params.set('operation', operation);
            return `/api/tenants/${tenant}/sites/${site}/operations?${params.toString()}`;
        },
        recheck: `/api/tenants/${tenant}/sites/${site}/connection/recheck`,
        sync: `/api/tenants/${tenant}/sites/${site}/sync`,
        run: (runId: number) => `/api/tenants/${tenant}/sync-runs/${runId}`,
    };
}

export function connectionCenterSyncOutcome(status: string): 'pending' | 'success' | 'failure' {
    const normalized = status.toLowerCase();
    if (normalized === 'succeeded') return 'success';
    if (['failed', 'cancelled', 'cancel_requested'].includes(normalized)) return 'failure';
    return 'pending';
}

const delay = (milliseconds: number) => new Promise((resolve) => window.setTimeout(resolve, milliseconds));

export function SiteConnectionCenterSyncControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const { siteId } = useParams();
    const endpoints = siteConnectionCenterEndpoints(context.tenant.slug, siteId ?? '');
    const canManage = context.permissions.includes('*') || context.permissions.includes('sites.manage');
    const canRetest = context.permissions.includes('*') || context.permissions.includes('connector.manage');
    const [connection, setConnection] = useState<ConnectionSnapshot | null>(null);
    const [history, setHistory] = useState<OperationHistoryItem[]>([]);
    const [historyPage, setHistoryPage] = useState(1);
    const [historyPageSize, setHistoryPageSize] = useState(25);
    const [historyStatus, setHistoryStatus] = useState('');
    const [historyOperation, setHistoryOperation] = useState('');
    const [historyPaging, setHistoryPaging] = useState<HistoryPaging | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const [copying, setCopying] = useState(false);
    const [retesting, setRetesting] = useState(false);
    const [siteNotFound, setSiteNotFound] = useState(false);

    const reload = useCallback(async () => {
        if (!endpoints) throw new ApiError('Invalid active site.', 404, 'invalid_site');

        const [nextConnection, nextOperations] = await Promise.all([
            apiRequest<ConnectionSnapshot>(endpoints.connection),
            apiRequest<OperationHistoryPayload>(endpoints.pagedOperations(historyPage, historyPageSize, historyStatus, historyOperation)),
        ]);

        if (Number(nextConnection.site?.id) !== Number(siteId)) {
            throw new ApiError('Authoritative connection state did not match the requested site.', 409, 'connection_site_mismatch');
        }

        setConnection(nextConnection);
        setHistory(Array.isArray(nextOperations.items) ? nextOperations.items : []);
        setHistoryPaging([nextOperations.total, nextOperations.page, nextOperations.last_page, nextOperations.per_page]
            .every((value) => Number.isSafeInteger(value) && Number(value) >= 0)
            ? {
                total: nextOperations.total!, page: nextOperations.page!,
                lastPage: Math.max(1, nextOperations.last_page!), perPage: nextOperations.per_page!,
            }
            : null);
        setSiteNotFound(false);

        return { connection: nextConnection, operations: nextOperations };
    }, [endpoints?.connection, endpoints?.operations, siteId, historyPage, historyPageSize, historyStatus, historyOperation]);

    useEffect(() => {
        setError('');
        reload().catch((cause: unknown) => {
            setConnection(null);
            setHistory([]);
            setHistoryPaging(null);
            setSiteNotFound(cause instanceof ApiError && cause.status === 404);
            setError(cause instanceof Error ? cause.message : 'Connection center unavailable.');
        });
    }, [reload]);

    const copyDiagnostics = async () => {
        if (!connection || copying) return;

        setCopying(true);
        setError('');
        setMessage('');

        try {
            const clipboard = navigator.clipboard;
            if (!clipboard || typeof clipboard.writeText !== 'function') {
                throw new Error(locale === 'ar' ? 'واجهة الحافظة غير متاحة في هذا المتصفح.' : 'Clipboard API is unavailable in this browser.');
            }

            await clipboard.writeText(formatSiteDiagnosticsReport(connection, history));
            setMessage(locale === 'ar' ? 'تم نسخ تقرير التشخيص.' : 'Diagnostic report copied.');
        } catch (cause: unknown) {
            setError(cause instanceof Error ? cause.message : 'Diagnostic report could not be copied.');
        } finally {
            setCopying(false);
        }
    };

    const retestConnection = async () => {
        if (!endpoints || !canRetest || retesting) return;

        setRetesting(true);
        setError('');
        setMessage('');

        try {
            await apiRequest(endpoints.recheck, { method: 'POST', body: JSON.stringify({}) });
            await reload();
            setMessage(locale === 'ar'
                ? 'اكتمل اختبار الاتصال وتمت إعادة قراءة الحالة وسجل العمليات من الخادم.'
                : 'Connection test completed and authoritative state plus operation history were reread from the server.');
        } catch (cause: unknown) {
            try { await reload(); } catch { /* keep the test failure primary */ }
            setMessage('');
            setError(cause instanceof Error ? cause.message : 'Connection test failed.');
        } finally {
            setRetesting(false);
        }
    };

    const synchronize = async () => {
        if (!endpoints || !canManage || busy) return;

        setBusy(true);
        setError('');
        setMessage('');
        const idempotencyKey = window.crypto.randomUUID();

        try {
            let run = await apiRequest<SyncRun>(endpoints.sync, {
                method: 'POST',
                headers: { 'Idempotency-Key': idempotencyKey },
                body: JSON.stringify({}),
            });

            for (let attempt = 0; attempt < 60 && connectionCenterSyncOutcome(run.status) === 'pending'; attempt += 1) {
                await delay(500);
                run = await apiRequest<SyncRun>(endpoints.run(run.id));
            }

            if (connectionCenterSyncOutcome(run.status) !== 'success') {
                throw new ApiError(
                    run.failure || 'Synchronization did not complete successfully.',
                    409,
                    run.diagnostic_code || 'sync_not_successful',
                    {},
                    { run_id: run.id },
                );
            }

            await reload();
            setMessage(locale === 'ar'
                ? 'اكتملت المزامنة وتمت إعادة قراءة حالة الاتصال وسجل العمليات من الخادم.'
                : 'Synchronization completed and connection state plus operation history were reread from the server.');
        } catch (cause: unknown) {
            try {
                await reload();
            } catch {
                // Preserve the synchronization failure as the primary user-visible error.
            }

            setMessage('');
            setError(formatSiteSyncDiagnostic(cause));
        } finally {
            setBusy(false);
        }
    };

    if (!endpoints) {
        return <div className="state-panel state-warning">{locale === 'ar' ? 'معرّف الموقع غير صالح.' : 'Invalid site identifier.'}</div>;
    }

    const siteUrl = safeExternalSiteUrl(connection?.site.url);
    const dynamicLinks = siteConnectionCenterDynamicLinks(context.tenant.slug, siteId, connection?.site);

    return (
        <div className="workspace-stack" data-canonical-operation={SITE_CONNECTION_CENTER_SYNC_OPERATION_ID}>
            <section className="hero-panel">
                <div>
                    <span className="workspace-kicker">WORDPRESS OPERATIONS</span>
                    <h2>{connection?.site.name ?? (locale === 'ar' ? 'مركز اتصال الموقع' : 'Site Connection Center')}</h2>
                    <p>
                        {locale === 'ar'
                            ? 'تشغيل مزامنة WordPress الفعلية ومراجعة حالة الاتصال وسجل العمليات من الخادم.'
                            : 'Run real WordPress synchronization and review authoritative connection and operation state.'}
                    </p>
                </div>
                <div className="d-flex gap-2 flex-wrap">
                    <button
                        type="button"
                        className="btn"
                        disabled={!connection || copying}
                        onClick={() => void copyDiagnostics()}
                        data-canonical-operation={SITE_CONNECTION_CENTER_COPY_DIAGNOSTICS_OPERATION_ID}
                    >
                        {copying
                            ? (locale === 'ar' ? 'جارٍ النسخ…' : 'Copying…')
                            : (locale === 'ar' ? 'نسخ التشخيص' : 'Copy diagnostics')}
                    </button>
                {canManage ? (
                    <button
                        type="button"
                        className="btn primary"
                        disabled={busy}
                        onClick={synchronize}
                        data-canonical-operation={SITE_CONNECTION_CENTER_SYNC_OPERATION_ID}
                    >
                        {busy
                            ? (locale === 'ar' ? 'جارٍ المزامنة…' : 'Synchronizing…')
                            : (locale === 'ar' ? 'مزامنة الآن' : 'Sync now')}
                    </button>
                ) : null}
                </div>
            </section>

            {error ? (
                <div role="alert" className="state-panel state-danger">
                    <p>{error}</p>
                    {siteNotFound ? (
                        <a
                            className="btn primary"
                            href={tenantUrl(context.tenant.slug, '/sites')}
                            data-testid="site-connection-back-to-sites"
                            data-canonical-operation={SITE_CONNECTION_CENTER_BACK_TO_SITES_OPERATION_ID}
                        >
                            {locale === 'ar' ? 'العودة للمواقع' : 'Back to sites'}
                        </a>
                    ) : null}
                </div>
            ) : null}
            {message ? <div role="status" className="state-panel state-success">{message}</div> : null}

            <section
                className="workspace-card"
                aria-label={locale === 'ar' ? 'رابط موقع WordPress' : 'WordPress site URL'}
                data-canonical-operation={SITE_CONNECTION_CENTER_SITE_URL_OPERATION_ID}
            >
                <span className="workspace-kicker">{locale === 'ar' ? 'الموقع المباشر' : 'LIVE SITE'}</span>
                {!connection && !error ? (
                    <p aria-busy="true">{locale === 'ar' ? 'جارٍ تحميل رابط الموقع…' : 'Loading site URL…'}</p>
                ) : siteUrl ? (
                    <a href={siteUrl} target="_blank" rel="noopener noreferrer">
                        <strong>{locale === 'ar' ? 'فتح الموقع' : 'Visit site'}</strong>
                        <small style={{ display: 'block' }}>{siteUrl} ↗</small>
                    </a>
                ) : connection ? (
                    <p>{locale === 'ar' ? 'رابط الموقع غير متاح.' : 'Site URL unavailable.'}</p>
                ) : null}
            </section>

            {dynamicLinks ? (
                <section
                    className="workspace-card"
                    aria-label={locale === 'ar' ? 'روابط الموقع الديناميكية' : 'Dynamic site navigation'}
                    data-testid="site-connection-dynamic-links"
                    data-canonical-operation={SITE_CONNECTION_CENTER_DYNAMIC_LINKS_OPERATION_ID}
                >
                    <div className="workspace-grid">
                        <a href={dynamicLinks.dashboard} data-testid="site-connection-dashboard-link">
                            <strong>{locale === 'ar' ? 'لوحة الموقع' : 'Site dashboard'}</strong>
                        </a>
                        {dynamicLinks.applicationPassword ? (
                            <a
                                href={dynamicLinks.applicationPassword}
                                target="_blank"
                                rel="noopener noreferrer"
                                data-testid="site-connection-application-password-link"
                            >
                                <strong>Application Password</strong>
                            </a>
                        ) : null}
                        <a href={dynamicLinks.localContent} data-testid="site-connection-local-content-link">
                            <strong>{locale === 'ar' ? 'المحتوى المحلي' : 'Local content'}</strong>
                        </a>
                        <a href={dynamicLinks.execution} data-testid="site-connection-execution-link">
                            <strong>{locale === 'ar' ? 'مركز التنفيذ' : 'Execution Center'}</strong>
                        </a>
                    </div>
                </section>
            ) : null}

            <section className="workspace-card">
                <strong>{locale === 'ar' ? 'حالة الاتصال' : 'Connection'}: {connection?.connection ?? '—'}</strong>
                <p>{locale === 'ar' ? 'حالة الموقع' : 'Site state'}: {connection?.site.connection_status ?? '—'}</p>
                <p data-testid="sync-health-state">{locale === 'ar' ? 'آخر حالة مزامنة' : 'Last synchronization'}: {connection?.sync_health?.state === 'succeeded'
                    ? (locale === 'ar' ? 'ناجحة' : 'Succeeded')
                    : connection?.sync_health?.state === 'failed'
                        ? (locale === 'ar' ? 'فاشلة — راجع سجل العمليات' : 'Failed — review operation history')
                        : (locale === 'ar' ? 'لم يتم التحقق' : 'Not yet verified')}</p>
                {connection?.sync_health?.last_attempt_at ? (
                    <small>{locale === 'ar' ? 'آخر محاولة مزامنة' : 'Last sync attempt'}: {connection.sync_health.last_attempt_at}</small>
                ) : null}
                {canRetest ? (
                    <button
                        type="button"
                        className="btn primary"
                        disabled={!connection || retesting}
                        onClick={() => void retestConnection()}
                        data-canonical-operation={SITE_CONNECTION_CENTER_RETEST_OPERATION_ID}
                    >
                        {retesting
                            ? (locale === 'ar' ? 'جارٍ الاختبار…' : 'Testing…')
                            : (locale === 'ar' ? 'تشغيل الاختبار' : 'Run test')}
                    </button>
                ) : null}
            </section>

            <section className="workspace-card">
                <h3>{locale === 'ar' ? 'سجل العمليات' : 'Operation history'}</h3>
                <div className="d-flex gap-2 flex-wrap" role="group" aria-label={locale === 'ar' ? 'تصفية سجل العمليات' : 'Filter operation history'}>
                    <label>{locale === 'ar' ? 'الحالة' : 'Status'}
                        <select aria-label="Operation status" value={historyStatus} onChange={(event) => { setHistoryPage(1); setHistoryStatus(event.target.value); }}>
                            <option value="">{locale === 'ar' ? 'كل الحالات' : 'All statuses'}</option>
                            <option value="succeeded">{locale === 'ar' ? 'ناجحة' : 'Succeeded'}</option>
                            <option value="failed">{locale === 'ar' ? 'فاشلة' : 'Failed'}</option>
                        </select>
                    </label>
                    <label>{locale === 'ar' ? 'العملية' : 'Operation'}
                        <select aria-label="Operation type" value={historyOperation} onChange={(event) => { setHistoryPage(1); setHistoryOperation(event.target.value); }}>
                            <option value="">{locale === 'ar' ? 'كل العمليات' : 'All operations'}</option>
                            <option value="synchronization">{locale === 'ar' ? 'مزامنة' : 'Synchronization'}</option>
                            <option value="diagnostic_recheck">{locale === 'ar' ? 'إعادة التشخيص' : 'Diagnostic recheck'}</option>
                            <option value="reconnect">{locale === 'ar' ? 'إعادة اتصال' : 'Reconnect'}</option>
                            <option value="disconnect">{locale === 'ar' ? 'قطع الاتصال' : 'Disconnect'}</option>
                        </select>
                    </label>
                </div>
                {history.length === 0 ? (
                    <p>{locale === 'ar' ? 'لا توجد عمليات مسجلة.' : 'No operations recorded yet.'}</p>
                ) : (
                    <div className="workspace-stack">
                        {history.map((item, index) => (
                            <article key={item.id ?? `${item.operation}-${item.started_at ?? index}`} className="state-panel">
                                <strong>{item.operation}</strong>
                                <span> · {item.status}</span>
                                <p>{item.message}</p>
                                {item.operation === 'synchronization' && item.status === 'failed' && item.details?.failure_code ? (
                                    <div className="workspace-stack" data-sync-failure-details>
                                        <small>{locale === 'ar' ? 'رمز الفشل' : 'Failure code'}: {item.details.failure_code}</small>
                                        <p>{locale === 'ar'
                                            ? (SYNC_FAILURE_AR[item.details.failure_category ?? ''] ?? SYNC_FAILURE_AR.unknown)
                                            : item.details.failure_message}</p>
                                        {Number.isSafeInteger(item.details.sync_run_id) && Number(item.details.sync_run_id) > 0 ? (
                                            <small>{locale === 'ar' ? 'رقم تشغيل المزامنة' : 'Synchronization run'}: #{item.details.sync_run_id}</small>
                                        ) : null}
                                    </div>
                                ) : null}
                                {(context.permissions.includes('*') || context.permissions.includes('execution.view'))
                                    && typeof item.correlation_id === 'string'
                                    && /^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(item.correlation_id) ? (
                                        <a href={tenantUrl(context.tenant.slug, `/site-operations/${encodeURIComponent(item.correlation_id)}`)}>
                                            {locale === 'ar' ? 'تفاصيل العملية' : 'Operation details'}
                                        </a>
                                    ) : null}
                                {item.affected_records != null ? (
                                    <small>{locale === 'ar' ? 'السجلات المتأثرة' : 'Affected records'}: {item.affected_records}</small>
                                ) : null}
                            </article>
                        ))}
                    </div>
                )}
                {historyPaging ? (
                    <Pagination page={historyPaging.page} lastPage={historyPaging.lastPage}
                        total={historyPaging.total} pageSize={historyPaging.perPage} returnedCount={history.length}
                        onPage={setHistoryPage} onPageSizeChange={(size) => { setHistoryPage(1); setHistoryPageSize(size); }} />
                ) : null}
            </section>
        </div>
    );
}
