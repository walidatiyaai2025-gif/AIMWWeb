import React, { useCallback, useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { ApiError, apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SITE_CONNECTION_CENTER_SYNC_OPERATION_ID = 'AIMW-BILL-3762C05261';

type ConnectionSnapshot = {
    site: {
        id: number;
        name: string;
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

export function siteConnectionCenterEndpoints(tenantSlug: string, siteId: string | number) {
    const raw = String(siteId).trim();
    if (!/^[1-9]\d*$/.test(raw) || !tenantSlug.trim() || /[\\/]/.test(tenantSlug)) return null;

    const tenant = encodeURIComponent(tenantSlug);
    const site = encodeURIComponent(raw);

    return {
        connection: `/api/tenants/${tenant}/sites/${site}/connection`,
        operations: `/api/tenants/${tenant}/sites/${site}/operations?take=100`,
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
    const [connection, setConnection] = useState<ConnectionSnapshot | null>(null);
    const [history, setHistory] = useState<OperationHistoryItem[]>([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');

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
            </section>

            {error ? <div role="alert" className="state-panel state-danger">{error}</div> : null}
            {message ? <div role="status" className="state-panel state-success">{message}</div> : null}

            <section className="workspace-card">
                <strong>{locale === 'ar' ? 'حالة الاتصال' : 'Connection'}: {connection?.connection ?? '—'}</strong>
                <p>{locale === 'ar' ? 'حالة الموقع' : 'Site state'}: {connection?.site.connection_status ?? '—'}</p>
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
