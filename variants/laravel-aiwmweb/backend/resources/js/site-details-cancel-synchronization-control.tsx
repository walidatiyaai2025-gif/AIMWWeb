import React from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SITE_DETAILS_CANCEL_SYNCHRONIZATION_OPERATION_ID = 'AIMW-AI-54BB64BB13';

type SyncRunSummary = {
    id: number;
    state: string;
    requested_at?: string | null;
    started_at?: string | null;
};

type ActiveSyncPayload = {
    active: boolean;
    run: SyncRunSummary | null;
};

type CancelSyncPayload = {
    operation_id: string;
    run: SyncRunSummary;
};

export function canonicalSyncCancellationEndpoints(syncEndpoint: string | undefined): { active: string; cancel: string } | null {
    if (!syncEndpoint || !/^\/api\/v1\/tenants\/[^/?#]+\/sites\/\d+\/sync$/.test(syncEndpoint)) {
        return null;
    }

    return {
        active: `${syncEndpoint}/active`,
        cancel: `${syncEndpoint}/cancel`,
    };
}

export function SiteDetailsCancelSynchronizationControl({ context }: { context: FrontendContext }) {
    const canEditContent = context.permissions.includes('*') || context.permissions.includes('content.edit');
    const endpoints = canonicalSyncCancellationEndpoints(context.api.sync);

    if (!canEditContent || !endpoints) {
        return null;
    }

    return <AuthorizedCancelSynchronizationControl context={context} endpoints={endpoints} />;
}

function AuthorizedCancelSynchronizationControl({
    context,
    endpoints,
}: {
    context: FrontendContext;
    endpoints: { active: string; cancel: string };
}) {
    const { locale } = useLocale();
    const queryClient = useQueryClient();
    const queryKey = ['site-details-active-sync', context.tenant.slug, endpoints.active] as const;
    const activeSync = useQuery({
        queryKey,
        queryFn: () => apiRequest<ActiveSyncPayload>(endpoints.active),
        retry: false,
        staleTime: 0,
        refetchInterval: 1500,
    });
    const cancellation = useMutation({
        mutationFn: () => apiRequest<CancelSyncPayload>(endpoints.cancel, { method: 'POST' }),
        onSuccess: (payload) => {
            queryClient.setQueryData<ActiveSyncPayload>(queryKey, { active: true, run: payload.run });
            void queryClient.invalidateQueries({ queryKey });
        },
    });

    if (!activeSync.data?.active || !activeSync.data.run) {
        return null;
    }

    const requested = activeSync.data.run.state === 'cancel_requested' || cancellation.isPending;
    const error = cancellation.error ?? activeSync.error;
    const errorMessage = error instanceof ApiError
        ? error.message
        : error instanceof Error
            ? error.message
            : null;

    return (
        <section
            className="toolbar-panel site-details-cancel-synchronization"
            aria-label={locale === 'ar' ? 'إلغاء مزامنة الموقع' : 'Cancel site synchronization'}
            data-canonical-operation={SITE_DETAILS_CANCEL_SYNCHRONIZATION_OPERATION_ID}
        >
            <div>
                <span className="workspace-kicker">{locale === 'ar' ? 'مزامنة جارية' : 'SYNCHRONIZATION IN PROGRESS'}</span>
                <p>
                    {locale === 'ar'
                        ? 'يطلب الإلغاء إيقاف المعالجة التعاونية للمزامنة الجارية. لا يُعرض الإلغاء على أنه نجاح للموصل أو WordPress.'
                        : 'Cancellation requests cooperative shutdown of the active synchronization. It does not report WordPress or connector success.'}
                </p>
                {errorMessage ? <p role="alert">{errorMessage}</p> : null}
            </div>
            <button
                type="button"
                className="btn danger"
                data-canonical-operation={SITE_DETAILS_CANCEL_SYNCHRONIZATION_OPERATION_ID}
                disabled={requested}
                onClick={() => cancellation.mutate()}
            >
                {requested
                    ? (locale === 'ar' ? 'تم طلب الإلغاء…' : 'Cancellation requested…')
                    : (locale === 'ar' ? 'إلغاء المزامنة' : 'Cancel synchronization')}
            </button>
        </section>
    );
}
