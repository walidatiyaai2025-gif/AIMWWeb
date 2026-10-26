import React, { useCallback, useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { ApiError, apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';
import { safeExternalSiteUrl } from './site-details-site-url-control';

export const SITE_CONNECTION_CENTER_SYNC_OPERATION_ID = 'AIMW-BILL-3762C05261';
export const SITE_CONNECTION_CENTER_COPY_DIAGNOSTICS_OPERATION_ID = 'AIMW-BILL-D4E1DA62D9';
export const SITE_CONNECTION_CENTER_RETEST_OPERATION_ID = 'AIMW-BILL-E747D0E843';
export const SITE_CONNECTION_CENTER_SITE_URL_OPERATION_ID = 'AIMW-CONT-63EDB4BA6F';

type ConnectionSnapshot = {
    site: {
        id: number;
        name: string;
        url?: unknown;
        connection_status?: string | null;
    };
    connection: string;
    last_verified_at?: string | null;
};

type OperationHistoryItem = {
    id?: number;
    operation: string;
    status: string;
    message: string;
    affected_records?: number | null;
    started_at?: string | null;
    completed_at?: string | null;
};

type OperationHistoryPayload = { items: OperationHistoryItem[] };
type SyncRun = { id: number; status: string; processed?: number; failure?: string | null };

export function formatSiteDiagnosticsReport(connection: ConnectionSnapshot, history: OperationHistoryItem[]): string {
    const site = connection.site as ConnectionSnapshot['site'] & { wordpress_version?: string | null; language_code?: string | null; last_verified_at?: string | null };
    const lines = [
        'AI WordPress Manager - Site Diagnostics',
        `Site: ${site.name}`,
        `URL: ${typeof site.url === 'string' ? site.url : '—'}`,
        `Status: ${site.connection_status ?? connection.connection ?? '—'}`,
        `WordPress: ${site.wordpress_version ?? '—'}`,
        `Language: ${site.language_code ?? '—'}`,
        `Last test: ${connection.last_verified_at ?? site.last_verified_at ?? '—'}`,
        '',
        'Recent operations:',
    ];

    for (const item of history.slice(0, 20)) {
        const started = item.started_at ?? '—';
        const outcome = item.status.toLowerCase() === 'succeeded' ? 'OK' : item.status.toUpperCase();
        lines.push(`[${started}] ${item.operation} ${outcome} - ${item.message}`);
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
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');
    const [copying, setCopying] = useState(false);
    const [retesting, setRetesting] = useState(false);

    const reload = useCallback(async () => {
        if (!endpoints) throw new ApiError('Invalid active site.', 404, 'invalid_site');

        const [nextConnection, nextOperations] = await Promise.all([
            apiRequest<ConnectionSnapshot>(endpoints.connection),
            apiRequest<OperationHistoryPayload>(endpoints.operations),
        ]);

        if (Number(nextConnection.site?.id) !== Number(siteId)) {
            throw new ApiError('Authoritative connection state did not match the requested site.', 409, 'connection_site_mismatch');
        }

        setConnection(nextConnection);
        setHistory(Array.isArray(nextOperations.items) ? nextOperations.items : []);

        return { connection: nextConnection, operations: nextOperations };
    }, [endpoints?.connection, endpoints?.operations, siteId]);

    useEffect(() => {
        setError('');
        reload().catch((cause: unknown) => {
            setConnection(null);
            setHistory([]);
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
                    'sync_not_successful',
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
            setError(cause instanceof Error ? cause.message : 'Synchronization failed.');
        } finally {
            setBusy(false);
        }
    };

    if (!endpoints) {
        return <div className="state-panel state-warning">{locale === 'ar' ? 'معرّف الموقع غير صالح.' : 'Invalid site identifier.'}</div>;
    }

    const siteUrl = safeExternalSiteUrl(connection?.site.url);

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

            {error ? <div role="alert" className="state-panel state-danger">{error}</div> : null}
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

            <section className="workspace-card">
                <strong>{locale === 'ar' ? 'حالة الاتصال' : 'Connection'}: {connection?.connection ?? '—'}</strong>
                <p>{locale === 'ar' ? 'حالة الموقع' : 'Site state'}: {connection?.site.connection_status ?? '—'}</p>
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
                {history.length === 0 ? (
                    <p>{locale === 'ar' ? 'لا توجد عمليات مسجلة.' : 'No operations recorded yet.'}</p>
                ) : (
                    <div className="workspace-stack">
                        {history.slice(0, 20).map((item, index) => (
                            <article key={item.id ?? `${item.operation}-${item.started_at ?? index}`} className="state-panel">
                                <strong>{item.operation}</strong>
                                <span> · {item.status}</span>
                                <p>{item.message}</p>
                                {item.affected_records != null ? (
                                    <small>{locale === 'ar' ? 'السجلات المتأثرة' : 'Affected records'}: {item.affected_records}</small>
                                ) : null}
                            </article>
                        ))}
                    </div>
                )}
            </section>
        </div>
    );
}
