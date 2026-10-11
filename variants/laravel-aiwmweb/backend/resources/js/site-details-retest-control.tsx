import React, { useMemo } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ApiError, apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';
import { AuthoritativeReconciliationError, mutateThenReconcile } from './reconciliation';

export const SITE_DETAILS_RETEST_OPERATION_ID = 'AIMW-BILL-D6B3460675';

type SiteDetailsPayload = {
    id?: number;
    name?: string;
    connection_status?: string | null;
    health_state?: string | null;
    last_verified_at?: string | null;
};

type DiagnosticPayload = {
    id?: number;
    classification?: string | null;
};

export function canonicalSiteDetailsRetestEndpoint(detailEndpoint: string | undefined): string | null {
    if (!detailEndpoint || !/^\/api\/tenants\/[^/?#]+\/sites\/\d+$/.test(detailEndpoint)) return null;
    return `${detailEndpoint}/connection/recheck`;
}

export function SiteDetailsRetestControl({ context }: { context: FrontendContext }) {
    const detailEndpoint = useMemo(() => {
        const endpoints = Object.entries(context.api)
            .filter(([key]) => key.startsWith('sites.detail.'))
            .map(([, endpoint]) => endpoint);
        return endpoints.length === 1 ? endpoints[0] : undefined;
    }, [context.api]);
    const retestEndpoint = canonicalSiteDetailsRetestEndpoint(detailEndpoint);
    const canRetest = context.permissions.includes('*') || context.permissions.includes('connector.manage');

    if (!canRetest || !detailEndpoint || !retestEndpoint) return null;

    return <AuthorizedSiteDetailsRetestControl
        context={context}
        detailEndpoint={detailEndpoint}
        retestEndpoint={retestEndpoint}
    />;
}

function AuthorizedSiteDetailsRetestControl({
    context,
    detailEndpoint,
    retestEndpoint,
}: {
    context: FrontendContext;
    detailEndpoint: string;
    retestEndpoint: string;
}) {
    const { locale } = useLocale();
    const details = useQuery({
        queryKey: ['canonical-site-details-retest', context.tenant.slug, detailEndpoint],
        queryFn: () => apiRequest<SiteDetailsPayload>(detailEndpoint),
        retry: false,
    });
    const retest = useMutation({
        mutationFn: async () => {
            let authoritative: SiteDetailsPayload | null = null;
            await mutateThenReconcile(
                () => apiRequest<DiagnosticPayload>(retestEndpoint, { method: 'POST', body: JSON.stringify({}) }),
                async () => {
                    const refreshed = await details.refetch();
                    if (refreshed.error) throw refreshed.error;
                    if (!refreshed.data) throw new Error('Connection retest completed but authoritative Site Details could not be reread.');
                    authoritative = refreshed.data;
                },
            );
            if (authoritative === null) throw new Error('Connection retest completed without an authoritative Site Details snapshot.');
            return authoritative;
        },
    });

    const error = retest.error ?? details.error;
    const errorMessage = error instanceof AuthoritativeReconciliationError
        ? error.message
        : error instanceof ApiError
            ? error.message
            : error instanceof Error
                ? error.message
                : null;

    return (
        <section
            className="toolbar-panel site-details-retest"
            aria-label={locale === 'ar' ? 'إعادة اختبار اتصال الموقع' : 'Retest site connection'}
            data-canonical-operation={SITE_DETAILS_RETEST_OPERATION_ID}
        >
            <div>
                <span className="workspace-kicker">{locale === 'ar' ? 'اختبار الاتصال' : 'CONNECTION RETEST'}</span>
                <p>
                    {locale === 'ar'
                        ? 'يعيد اختبار الاتصال المحفوظ فعليًا ثم يعيد قراءة تفاصيل الموقع من الخادم قبل إظهار النجاح.'
                        : 'Runs the saved connection test for real, then rereads authoritative Site Details before reporting completion.'}
                </p>
                {details.data?.connection_status ? (
                    <small>{locale === 'ar' ? 'الحالة' : 'Status'}: {details.data.connection_status}</small>
                ) : null}
                {errorMessage ? <p role="alert">{errorMessage}</p> : null}
            </div>
            <button
                type="button"
                className="btn"
                data-canonical-operation={SITE_DETAILS_RETEST_OPERATION_ID}
                disabled={retest.isPending || details.isLoading}
                onClick={() => retest.mutate()}
            >
                {retest.isPending
                    ? (locale === 'ar' ? 'جارٍ الاختبار…' : 'Testing…')
                    : (locale === 'ar' ? 'إعادة الاختبار' : 'Retest')}
            </button>
        </section>
    );
}
