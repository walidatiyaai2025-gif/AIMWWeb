import React from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiRequest, type FrontendContext } from './core';
import { useToast } from './components';
import { useLocale } from './i18n';

export const CONTENT_EXPLORER_SYNCHRONIZE_OPERATION_ID = 'AIMW-BILL-5DC460397B';

type ActiveSite = {
    id: number;
    name: string;
};

type ContextWithActiveSite = FrontendContext & {
    active_site?: ActiveSite | null;
};

export type ExplorerSyncRun = {
    id: number;
    site_id: number;
    status: string;
    processed?: number;
    failure?: string | null;
    idempotent_replay?: boolean;
};

export function contentExplorerSynchronizeEndpoints(
    tenantSlug: string,
    siteId: string | number,
) {
    const site = String(siteId).trim();
    if (!tenantSlug.trim() || /[\\/]/.test(tenantSlug)) return null;
    if (!/^[1-9]\d*$/.test(site)) return null;

    const tenant = encodeURIComponent(tenantSlug);
    const encodedSite = encodeURIComponent(site);

    return {
        start: `/api/tenants/${tenant}/sites/${encodedSite}/sync`,
        run: (runId: string | number) => {
            const run = String(runId).trim();
            if (!/^[1-9]\d*$/.test(run)) return null;
            return `/api/tenants/${tenant}/sync-runs/${encodeURIComponent(run)}`;
        },
    };
}

export function contentExplorerSyncOutcome(status: string): 'pending' | 'success' | 'failure' {
    const normalized = status.trim().toLowerCase();
    if (normalized === 'succeeded' || normalized === 'completed') return 'success';
    if (['failed', 'partial', 'cancelled', 'cancel_requested'].includes(normalized)) return 'failure';
    return 'pending';
}

function nextIdempotencyKey(): string {
    if (typeof globalThis.crypto?.randomUUID === 'function') return globalThis.crypto.randomUUID();
    return `content-explorer-sync-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

const delay = (milliseconds: number) => new Promise((resolve) => window.setTimeout(resolve, milliseconds));

export function ContentExplorerSynchronizeControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const { notify } = useToast();
    const queryClient = useQueryClient();
    const activeSite = (context as ContextWithActiveSite).active_site;
    const siteId = activeSite?.id ?? 0;
    const canSynchronize = context.permissions.includes('*') || context.permissions.includes('sites.manage');
    const endpoints = contentExplorerSynchronizeEndpoints(context.tenant.slug, siteId);

    const mutation = useMutation({
        mutationFn: async () => {
            if (!endpoints || !activeSite) throw new Error('Synchronization endpoint is unavailable.');

            let run = await apiRequest<ExplorerSyncRun>(endpoints.start, {
                method: 'POST',
                headers: { 'Idempotency-Key': nextIdempotencyKey() },
                body: JSON.stringify({}),
            });

            for (let attempt = 0; attempt < 60 && contentExplorerSyncOutcome(run.status) === 'pending'; attempt += 1) {
                const runEndpoint = endpoints.run(run.id);
                if (!runEndpoint) throw new ApiError('Invalid synchronization run.', 409, 'invalid_sync_run');
                await delay(500);
                run = await apiRequest<ExplorerSyncRun>(runEndpoint);
            }

            if (contentExplorerSyncOutcome(run.status) !== 'success') {
                throw new ApiError(
                    run.failure || 'Synchronization did not complete successfully.',
                    409,
                    'sync_not_successful',
                );
            }

            return run;
        },
        onSuccess: async (run) => {
            await queryClient.invalidateQueries({
                queryKey: ['content-explorer-bulk-trash', context.tenant.slug, siteId],
            });
            notify(
                locale === 'ar'
                    ? `اكتملت المزامنة. تمت معالجة ${run.processed ?? 0} سجل وإعادة تحميل بيانات المستكشف.`
                    : `Synchronization completed. ${run.processed ?? 0} record(s) were processed and Explorer data was refreshed.`,
                'success',
            );
        },
        onError: (error) => notify(
            error instanceof Error
                ? error.message
                : (locale === 'ar' ? 'فشلت مزامنة WordPress.' : 'WordPress synchronization failed.'),
            'error',
        ),
    });

    if (!activeSite || !endpoints || !canSynchronize) return null;

    return (
        <section
            className="panel data-panel"
            data-canonical-operation={CONTENT_EXPLORER_SYNCHRONIZE_OPERATION_ID}
            aria-label={locale === 'ar' ? 'مزامنة مستكشف WordPress' : 'Synchronize WordPress Explorer'}
        >
            <header className="panel-header">
                <div>
                    <span className="workspace-kicker">CONTENT OPERATIONS</span>
                    <h2>{locale === 'ar' ? 'مزامنة محتوى WordPress' : 'Synchronize WordPress content'}</h2>
                    <p>
                        {locale === 'ar'
                            ? `الموقع: ${activeSite.name}. يتم تشغيل المزامنة الفعلية ثم إعادة قراءة بيانات المستكشف من الخادم.`
                            : `Site: ${activeSite.name}. Runs the real synchronization workflow, then rereads Explorer data from the server.`}
                    </p>
                </div>
                <button
                    type="button"
                    className="btn primary"
                    onClick={() => mutation.mutate()}
                    disabled={mutation.isPending}
                    data-canonical-operation={CONTENT_EXPLORER_SYNCHRONIZE_OPERATION_ID}
                >
                    {mutation.isPending
                        ? (locale === 'ar' ? 'المزامنة تعمل…' : 'Synchronizing…')
                        : (locale === 'ar' ? '↻ مزامنة الآن' : '↻ Sync now')}
                </button>
            </header>
        </section>
    );
}
