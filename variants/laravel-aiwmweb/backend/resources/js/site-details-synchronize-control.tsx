import React, { useMemo, useState } from 'react';
import { ApiError, apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SITE_DETAILS_SYNCHRONIZE_OPERATION_ID = 'AIMW-BILL-94755B17E4';

type SyncRun = {
    id: number;
    status: string;
    processed?: number;
    failure?: string | null;
};

type SiteDetailsPayload = {
    id?: number;
    name?: string;
    connection_status?: string | null;
};

export function canonicalSiteDetailsSyncEndpoints(detailEndpoint: string | undefined) {
    if (!detailEndpoint || !/^\/api\/tenants\/[^/?#]+\/sites\/\d+$/.test(detailEndpoint)) return null;
    const match = detailEndpoint.match(/^\/api\/tenants\/([^/?#]+)\/sites\/(\d+)$/);
    if (!match) return null;
    const [, tenant, site] = match;
    return {
        detail: detailEndpoint,
        sync: `/api/tenants/${tenant}/sites/${site}/sync`,
        run: (runId: number) => `/api/tenants/${tenant}/sync-runs/${runId}`,
    };
}

export function siteDetailsSyncOutcome(status: string): 'pending' | 'success' | 'failure' {
    const normalized = status.toLowerCase();
    if (normalized === 'succeeded') return 'success';
    if (['failed', 'cancelled', 'cancel_requested'].includes(normalized)) return 'failure';
    return 'pending';
}

const delay = (milliseconds: number) => new Promise((resolve) => window.setTimeout(resolve, milliseconds));

export function SiteDetailsSynchronizeControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const detailEndpoint = useMemo(() => {
        const endpoints = Object.entries(context.api)
            .filter(([key]) => key.startsWith('sites.detail.'))
            .map(([, endpoint]) => endpoint);
        return endpoints.length === 1 ? endpoints[0] : undefined;
    }, [context.api]);
    const endpoints = canonicalSiteDetailsSyncEndpoints(detailEndpoint);
    const canManage = context.permissions.includes('*') || context.permissions.includes('sites.manage');
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');
    const [site, setSite] = useState<SiteDetailsPayload | null>(null);

    if (!canManage || !endpoints) return null;

    const synchronize = async () => {
        if (busy) return;

        setBusy(true);
        setMessage('');
        setError('');
        const idempotencyKey = window.crypto.randomUUID();

        try {
            let run = await apiRequest<SyncRun>(endpoints.sync, {
                method: 'POST',
                headers: { 'Idempotency-Key': idempotencyKey },
                body: JSON.stringify({}),
            });

            for (let attempt = 0; attempt < 60 && siteDetailsSyncOutcome(run.status) === 'pending'; attempt += 1) {
                await delay(500);
                run = await apiRequest<SyncRun>(endpoints.run(run.id));
            }

            if (siteDetailsSyncOutcome(run.status) !== 'success') {
                throw new ApiError(
                    run.failure || 'Synchronization did not complete successfully.',
                    409,
                    'sync_not_successful',
                );
            }

            const authoritative = await apiRequest<SiteDetailsPayload>(endpoints.detail);
            if (!authoritative.id) throw new Error('Synchronization completed but authoritative Site Details could not be reread.');
            setSite(authoritative);
            setMessage(locale === 'ar'
                ? 'اكتملت المزامنة وتمت إعادة قراءة تفاصيل الموقع من الخادم.'
                : 'Synchronization completed and authoritative Site Details were reread from the server.');
        } catch (cause: unknown) {
            try {
                const authoritative = await apiRequest<SiteDetailsPayload>(endpoints.detail);
                setSite(authoritative);
            } catch {
                // Preserve the synchronization failure as the primary visible error.
            }
            setMessage('');
            setError(cause instanceof Error ? cause.message : 'Synchronization failed.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <section
            className="toolbar-panel site-details-synchronize"
            aria-label={locale === 'ar' ? 'مزامنة الموقع' : 'Synchronize site'}
            data-canonical-operation={SITE_DETAILS_SYNCHRONIZE_OPERATION_ID}
        >
            <div>
                <span className="workspace-kicker">{locale === 'ar' ? 'المزامنة' : 'SYNCHRONIZATION'}</span>
                <p>
                    {locale === 'ar'
                        ? 'يشغّل مزامنة WordPress الفعلية ثم يعيد قراءة تفاصيل الموقع من الخادم قبل إظهار النجاح.'
                        : 'Runs real WordPress synchronization, then rereads authoritative Site Details before reporting success.'}
                </p>
                {site?.name ? <small>{site.name}</small> : null}
                {error ? <p role="alert">{error}</p> : null}
                {message ? <p role="status">{message}</p> : null}
            </div>
            <button
                type="button"
                className="btn primary"
                data-canonical-operation={SITE_DETAILS_SYNCHRONIZE_OPERATION_ID}
                disabled={busy}
                onClick={() => void synchronize()}
            >
                {busy
                    ? (locale === 'ar' ? 'جارٍ المزامنة…' : 'Synchronizing…')
                    : (locale === 'ar' ? 'مزامنة الآن' : 'Sync now')}
            </button>
        </section>
    );
}
