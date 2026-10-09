import React, { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { ApiError, apiRequest, type FrontendContext } from './core';
import { useToast } from './components';
import { useLocale } from './i18n';

export const GLOBAL_SYNCHRONIZATION_ACCEPT_REMOTE_OPERATION_ID = 'AIMW-BILL-6928C148FF';

type ActiveSite = {
    id: number;
    name: string;
};

type ContextWithActiveSite = FrontendContext & {
    active_site?: ActiveSite | null;
};

type AcceptRemoteRun = {
    operation_id: string;
    id: number;
    site_id: number;
    state: string;
    mode: string;
    trigger: string;
    idempotent_replay?: boolean;
};

type SyncRunEnvelope = {
    run: {
        id: number;
        site_id: number;
        state: string;
        mode: string;
        completed_at?: string | null;
    };
};

export function globalSynchronizationAcceptRemoteEndpoints(tenantSlug: string, siteId: number | string) {
    const tenant = tenantSlug.trim();
    const site = String(siteId).trim();
    if (!tenant || /[\\/]/.test(tenant)) return null;
    if (!/^[1-9]\d*$/.test(site)) return null;

    const prefix = `/api/v1/tenants/${encodeURIComponent(tenant)}/sites/${encodeURIComponent(site)}/sync`;

    return {
        accept: `${prefix}/accept-remote`,
        run: (runId: number | string) => {
            const id = String(runId).trim();
            if (!/^[1-9]\d*$/.test(id)) return null;
            return `${prefix}/runs/${encodeURIComponent(id)}`;
        },
    };
}

export function acceptRemoteSyncOutcome(state: string): 'pending' | 'success' | 'failure' {
    const normalized = state.trim().toLowerCase();
    if (normalized === 'completed') return 'success';
    if (['failed', 'partial', 'cancelled', 'cancel_requested'].includes(normalized)) return 'failure';
    return 'pending';
}

function nextIdempotencyKey(): string {
    if (typeof globalThis.crypto?.randomUUID === 'function') return globalThis.crypto.randomUUID();
    return `accept-remote-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

const delay = (milliseconds: number) => new Promise((resolve) => window.setTimeout(resolve, milliseconds));

export function GlobalSynchronizationAcceptRemoteControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const { notify } = useToast();
    const [confirmOpen, setConfirmOpen] = useState(false);
    const activeSite = (context as ContextWithActiveSite).active_site;
    const endpoints = globalSynchronizationAcceptRemoteEndpoints(context.tenant.slug, activeSite?.id ?? 0);
    const canEdit = context.permissions.includes('*') || context.permissions.includes('content.edit');

    const mutation = useMutation({
        mutationFn: async () => {
            if (!activeSite || !endpoints) throw new Error('Synchronization endpoint is unavailable.');

            const accepted = await apiRequest<AcceptRemoteRun>(endpoints.accept, {
                method: 'POST',
                headers: { 'Idempotency-Key': nextIdempotencyKey() },
                body: JSON.stringify({}),
            });

            if (accepted.operation_id !== GLOBAL_SYNCHRONIZATION_ACCEPT_REMOTE_OPERATION_ID) {
                throw new ApiError('Unexpected synchronization operation identity.', 409, 'operation_identity_mismatch');
            }
            if (accepted.mode !== 'full' || accepted.trigger !== 'accept-remote') {
                throw new ApiError('The server did not start a full accept-remote synchronization.', 409, 'sync_mode_mismatch');
            }

            let state = accepted.state;
            for (let attempt = 0; attempt < 120 && acceptRemoteSyncOutcome(state) === 'pending'; attempt += 1) {
                const runEndpoint = endpoints.run(accepted.id);
                if (!runEndpoint) throw new ApiError('Invalid synchronization run.', 409, 'invalid_sync_run');
                await delay(500);
                const status = await apiRequest<SyncRunEnvelope>(runEndpoint);
                if (status.run.id !== accepted.id || status.run.site_id !== activeSite.id || status.run.mode !== 'full') {
                    throw new ApiError('Synchronization status did not match the accepted run.', 409, 'sync_status_mismatch');
                }
                state = status.run.state;
            }

            if (acceptRemoteSyncOutcome(state) !== 'success') {
                throw new ApiError('Full WordPress synchronization did not complete successfully.', 409, 'sync_not_successful');
            }

            return accepted;
        },
        onSuccess: () => {
            setConfirmOpen(false);
            notify(
                locale === 'ar'
                    ? 'تم اعتماد نسخة WordPress وإكمال المزامنة الكاملة. يتم تحديث الحالة الموثوقة.'
                    : 'WordPress was accepted and the full synchronization completed. Refreshing authoritative state.',
                'success',
            );
            window.location.reload();
        },
        onError: (error) => notify(
            error instanceof Error
                ? error.message
                : (locale === 'ar' ? 'فشل اعتماد نسخة WordPress.' : 'Accepting the WordPress version failed.'),
            'error',
        ),
    });

    if (!activeSite || !endpoints || !canEdit) return null;

    return (
        <section
            className="panel data-panel"
            data-canonical-operation={GLOBAL_SYNCHRONIZATION_ACCEPT_REMOTE_OPERATION_ID}
            aria-label={locale === 'ar' ? 'اعتماد نسخة WordPress' : 'Accept WordPress version'}
        >
            <header className="panel-header">
                <div>
                    <span className="workspace-kicker">SYNC & CONFLICTS</span>
                    <h2>{locale === 'ar' ? 'اعتماد WordPress ومزامنة كاملة' : 'Accept WordPress & force full sync'}</h2>
                    <p>
                        {locale === 'ar'
                            ? `الموقع: ${activeSite.name}. يستبدل المرآة المحلية بقراءة كاملة جديدة من WordPress ولا يرسل محتوى محليًا قديمًا إلى الموقع.`
                            : `Site: ${activeSite.name}. Replaces the local mirror with a fresh full WordPress read and does not push stale local content to the site.`}
                    </p>
                </div>
                <button
                    type="button"
                    className="btn primary"
                    onClick={() => setConfirmOpen(true)}
                    disabled={mutation.isPending}
                >
                    {locale === 'ar' ? 'اعتماد WordPress' : 'Accept WordPress'}
                </button>
            </header>

            {confirmOpen ? (
                <div className="panel" role="dialog" aria-modal="true" aria-label={locale === 'ar' ? 'تأكيد اعتماد WordPress' : 'Confirm accepting WordPress'}>
                    <h3>{locale === 'ar' ? 'اعتماد نسخة WordPress؟' : 'Accept the WordPress version?'}</h3>
                    <p>
                        {locale === 'ar'
                            ? 'سيتم تشغيل مزامنة كاملة من WordPress وتطبيق حالة الحذف البعيدة على المرآة المحلية بعد التحقق.'
                            : 'A full WordPress synchronization will run and remote deletion state will be reconciled into the local mirror after verification.'}
                    </p>
                    <div className="button-row">
                        <button
                            type="button"
                            className="btn primary"
                            onClick={() => mutation.mutate()}
                            disabled={mutation.isPending}
                        >
                            {mutation.isPending
                                ? (locale === 'ar' ? 'جارٍ المزامنة…' : 'Synchronizing…')
                                : (locale === 'ar' ? 'اعتماد ومزامنة كاملة' : 'Accept & force full sync')}
                        </button>
                        <button
                            type="button"
                            className="btn"
                            onClick={() => setConfirmOpen(false)}
                            disabled={mutation.isPending}
                        >
                            {locale === 'ar' ? 'إلغاء' : 'Cancel'}
                        </button>
                    </div>
                </div>
            ) : null}
        </section>
    );
}
