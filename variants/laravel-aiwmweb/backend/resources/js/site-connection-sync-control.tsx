import React, { useCallback, useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { ApiError, apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SITE_CONNECTION_SYNC_OPERATION_ID = 'AIMW-BILL-3762C05261';

type ConnectionState = {
    site: { id: number; name?: string; connection_status?: string };
    connection?: string;
    connector?: { identity?: string; revoked?: boolean } | null;
    last_verified_at?: string | null;
};

type OperationHistory = {
    items: Array<{
        id?: number;
        operation?: string;
        status?: string;
        message?: string;
        affected_records?: number | null;
        started_at?: string | null;
        completed_at?: string | null;
    }>;
};

type SyncRun = {
    id: number;
    site_id?: number;
    status: string;
    processed?: number;
    failure?: string | null;
};

export function siteConnectionEndpoints(tenantSlug: string, siteId: string | number) {
    const raw = String(siteId).trim();
    if (!/^[1-9]\d*$/.test(raw) || !tenantSlug.trim() || /[\\/]/.test(tenantSlug)) return null;
    const tenant = encodeURIComponent(tenantSlug);
    const site = encodeURIComponent(raw);
    return {
        connection: `/api/tenants/${tenant}/sites/${site}/connection`,
        operations: `/api/tenants/${tenant}/sites/${site}/operations?take=20`,
        sync: `/api/tenants/${tenant}/sites/${site}/sync`,
        run: (runId: number) => `/api/tenants/${tenant}/sync-runs/${runId}`,
    };
}

export function connectionSyncOutcome(status: string): 'pending' | 'success' | 'failure' {
    const normalized = status.toLowerCase();
    if (normalized === 'succeeded') return 'success';
    if (['failed', 'cancelled', 'cancel_requested'].includes(normalized)) return 'failure';
    return 'pending';
}

const delay = (milliseconds: number) => new Promise((resolve) => window.setTimeout(resolve, milliseconds));

export function SiteConnectionCenterSyncControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const { siteId } = useParams();
    const endpoints = siteConnectionEndpoints(context.tenant.slug, siteId ?? '');
    const canManage = context.permissions.includes('*') || context.permissions.includes('sites.manage');
    const [connection, setConnection] = useState<ConnectionState | null>(null);
    const [history, setHistory] = useState<OperationHistory['items']>([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');

    const reload = useCallback(async () => {
        if (!endpoints) throw new ApiError('Invalid active site.', 404, 'invalid_site');
        const [nextConnection, nextHistory] = await Promise.all([
            apiRequest<ConnectionState>(endpoints.connection),
            apiRequest<OperationHistory>(endpoints.operations),
        ]);
        if (Number(nextConnection.site?.id) !== Number(siteId)) {
            throw new ApiError('Authoritative connection state did not match the requested site.', 409, 'site_mismatch');
        }
        setConnection(nextConnection);
        setHistory(Array.isArray(nextHistory.items) ? nextHistory.items : []);
        return { connection: nextConnection, history: nextHistory.items ?? [] };
    }, [endpoints?.connection, endpoints?.operations, siteId]);

    useEffect(() => {
        setError('');
        reload().catch((cause: unknown) => {
            setConnection(null);
            setHistory([]);
            setError(cause instanceof Error ? cause.message : 'Connection center unavailable.');
        });
    }, [reload]);

    const connectorReady = Boolean(connection?.connector && !connection.connector.revoked);

    const synchronize = async () => {
        if (!endpoints || !canManage || !connectorReady || busy) return;
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
            if (run.site_id !== undefined && run.site_id !== Number(siteId)) {
                throw new ApiError('Synchronization run belongs to a different site.', 409, 'sync_site_mismatch');
            }

            for (let attempt = 0; attempt < 60 && connectionSyncOutcome(run.status) === 'pending'; attempt += 1) {
                await delay(500);
                run = await apiRequest<SyncRun>(endpoints.run(run.id));
                if (run.site_id !== undefined && run.site_id !== Number(siteId)) {
                    throw new ApiError('Synchronization run belongs to a different site.', 409, 'sync_site_mismatch');
                }
            }

            if (connectionSyncOutcome(run.status) !== 'success') {
                throw new ApiError(run.failure || 'Synchronization did not complete successfully.', 409, 'sync_not_successful');
            }

            await reload();
            setMessage(locale === 'ar'
                ? 'اكتملت المزامنة وتم تحديث حالة الاتصال وسجل العمليات من الخادم.'
                : 'Synchronization completed and connection state and operation history were refreshed from the server.');
        } catch (cause: unknown) {
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
        <div className="workspace-stack" data-canonical-operation={SITE_CONNECTION_SYNC_OPERATION_ID}>
            <section className="hero-panel">
                <div>
                    <span className="workspace-kicker">WORDPRESS OPERATIONS</span>
                    <h2>{connection?.site?.name ?? (locale === 'ar' ? 'مركز اتصال الموقع' : 'Site Connection Center')}</h2>
                    <p>{locale === 'ar'
                        ? 'اختبر حالة الاتصال وشغّل مزامنة WordPress الحقيقية ثم راجع النتيجة من الخادم.'
                        : 'Inspect connection state, run the real WordPress synchronization, then reread the authoritative result.'}</p>
                </div>
                <button
                    type="button"
                    className="btn primary"
                    disabled={busy || !canManage || !connectorReady}
                    onClick={synchronize}
                    data-canonical-operation={SITE_CONNECTION_SYNC_OPERATION_ID}
                >
                    {busy ? (locale === 'ar' ? 'جارٍ المزامنة…' : 'Synchronizing…') : (locale === 'ar' ? 'مزامنة الآن' : 'Sync now')}
                </button>
            </section>
            {!canManage ? <div className="state-panel state-warning">{locale === 'ar' ? 'تحتاج صلاحية إدارة المواقع لتشغيل المزامنة.' : 'Site management permission is required to synchronize.'}</div> : null}
            {canManage && connection && !connectorReady ? <div className="state-panel state-warning">{locale === 'ar' ? 'يلزم موصل WordPress صالح قبل المزامنة.' : 'A valid WordPress connector is required before synchronization.'}</div> : null}
            {error ? <div role="alert" className="state-panel state-danger">{error}</div> : null}
            {message ? <div role="status" className="state-panel state-success">{message}</div> : null}
            <section className="panel settings-section">
                <header><div><span className="workspace-kicker">CONNECTION</span><h3>{locale === 'ar' ? 'حالة الاتصال' : 'Connection status'}</h3></div></header>
                <p>{connection?.connection ?? connection?.site?.connection_status ?? (locale === 'ar' ? 'غير متاح' : 'Unavailable')}</p>
            </section>
            <section className="panel settings-section">
                <header><div><span className="workspace-kicker">OPERATION HISTORY</span><h3>{locale === 'ar' ? 'العمليات الأخيرة' : 'Recent operations'}</h3></div></header>
                <p>{locale === 'ar' ? `عدد السجلات: ${history.length}` : `Recent records: ${history.length}`}</p>
            </section>
        </div>
    );
}
