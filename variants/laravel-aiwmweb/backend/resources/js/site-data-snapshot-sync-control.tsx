import React, { useCallback, useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { ApiError, apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SITE_DATA_SNAPSHOT_SYNC_OPERATION_ID = 'AIMW-BILL-2D6F2BC88E';

type Snapshot = {
    operation_id: string;
    site: { id: number; name: string; connection_status: string; last_sync_at: string | null };
    cached: { total: number; by_type: Record<string, number> };
    latest_run: { id: number; status: string; processed: number; failure: string | null; completed_at: string | null } | null;
};

type SyncRun = { id: number; status: string; processed?: number; failure?: string | null };

export function siteSnapshotEndpoints(tenantSlug: string, siteId: string | number) {
    const raw = String(siteId).trim();
    if (!/^[1-9]\d*$/.test(raw) || !tenantSlug.trim() || /[\\/]/.test(tenantSlug)) return null;
    const tenant = encodeURIComponent(tenantSlug);
    const site = encodeURIComponent(raw);
    return {
        snapshot: `/api/tenants/${tenant}/sites/${site}/snapshot`,
        sync: `/api/tenants/${tenant}/sites/${site}/sync`,
        run: (runId: number) => `/api/tenants/${tenant}/sync-runs/${runId}`,
    };
}

export function syncRunOutcome(status: string): 'pending' | 'success' | 'failure' {
    const normalized = status.toLowerCase();
    if (normalized === 'succeeded') return 'success';
    if (['failed', 'cancelled', 'cancel_requested'].includes(normalized)) return 'failure';
    return 'pending';
}

const delay = (milliseconds: number) => new Promise((resolve) => window.setTimeout(resolve, milliseconds));

export function SiteDataSnapshotSyncControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const { siteId } = useParams();
    const endpoints = siteSnapshotEndpoints(context.tenant.slug, siteId ?? '');
    const canManage = context.permissions.includes('*') || context.permissions.includes('sites.manage');
    const [snapshot, setSnapshot] = useState<Snapshot | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');

    const reload = useCallback(async () => {
        if (!endpoints) throw new ApiError('Invalid active site.', 404, 'invalid_site');
        const data = await apiRequest<Snapshot>(endpoints.snapshot);
        if (data.operation_id !== SITE_DATA_SNAPSHOT_SYNC_OPERATION_ID || data.site.id !== Number(siteId)) {
            throw new ApiError('Authoritative snapshot did not match the requested site.', 409, 'snapshot_mismatch');
        }
        setSnapshot(data);
        return data;
    }, [endpoints?.snapshot, siteId]);

    useEffect(() => {
        setError('');
        reload().catch((cause: unknown) => {
            setSnapshot(null);
            setError(cause instanceof Error ? cause.message : 'Snapshot unavailable.');
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

            for (let attempt = 0; attempt < 60 && syncRunOutcome(run.status) === 'pending'; attempt += 1) {
                await delay(500);
                run = await apiRequest<SyncRun>(endpoints.run(run.id));
            }

            const outcome = syncRunOutcome(run.status);
            if (outcome !== 'success') {
                throw new ApiError(run.failure || 'Synchronization did not complete successfully.', 409, 'sync_not_successful');
            }

            await reload();
            setMessage(locale === 'ar'
                ? 'اكتملت المزامنة وتمت إعادة قراءة البيانات المحفوظة من الخادم.'
                : 'Synchronization completed and cached data was reread from the server.');
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
        <div className="workspace-stack" data-canonical-operation={SITE_DATA_SNAPSHOT_SYNC_OPERATION_ID}>
            <section className="hero-panel">
                <div>
                    <span className="workspace-kicker">LOCAL DATA</span>
                    <h2>{snapshot?.site.name ?? (locale === 'ar' ? 'لقطة بيانات الموقع' : 'Site Data Snapshot')}</h2>
                    <p>{locale === 'ar' ? 'بيانات محلية فعلية مع مزامنة WordPress ومصالحة بعد اكتمال المهمة.' : 'Authoritative local data with WordPress synchronization and post-run reconciliation.'}</p>
                </div>
                {canManage ? (
                    <button
                        type="button"
                        className="btn primary"
                        disabled={busy}
                        onClick={synchronize}
                        data-canonical-operation={SITE_DATA_SNAPSHOT_SYNC_OPERATION_ID}
                    >
                        {busy ? (locale === 'ar' ? 'جارٍ المزامنة…' : 'Synchronizing…') : (locale === 'ar' ? 'مزامنة الآن' : 'Sync now')}
                    </button>
                ) : null}
            </section>
            {error ? <div role="alert" className="state-panel state-danger">{error}</div> : null}
            {message ? <div role="status" className="state-panel state-success">{message}</div> : null}
            <section className="workspace-card">
                <strong>{locale === 'ar' ? 'السجلات المحفوظة' : 'Cached records'}: {snapshot?.cached.total ?? '—'}</strong>
                <p>{locale === 'ar' ? 'آخر مزامنة' : 'Last synchronization'}: {snapshot?.site.last_sync_at ?? '—'}</p>
                <p>{locale === 'ar' ? 'آخر حالة' : 'Latest run'}: {snapshot?.latest_run?.status ?? '—'}</p>
            </section>
        </div>
    );
}
