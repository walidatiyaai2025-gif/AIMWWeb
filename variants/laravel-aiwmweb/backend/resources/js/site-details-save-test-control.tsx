import React, { useMemo } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ApiError, apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';
import { AuthoritativeReconciliationError, mutateThenReconcile } from './reconciliation';

export const SITE_DETAILS_SAVE_TEST_OPERATION_ID = 'AIMW-AI-387F3E5D5F';

type SiteDetailsPayload = {
    id?: number;
    name?: string;
    connection_status?: string | null;
    health_state?: string | null;
    last_verified_at?: string | null;
};

type HealthPayload = {
    status?: string;
    message?: string;
};

export function canonicalSiteVerifyEndpoint(detailEndpoint: string | undefined): string | null {
    if (!detailEndpoint) return null;
    const match = detailEndpoint.match(/^\/api\/tenants\/([^/]+)\/sites\/(\d+)$/);
    if (!match) return null;
    return `${detailEndpoint}/verify`;
}

export function SiteDetailsSaveTestControl({ context }: { context: FrontendContext }) {
    const detailEndpoint = useMemo(() => {
        const candidates = Object.entries(context.api)
            .filter(([key]) => key.startsWith('sites.detail.'))
            .map(([, endpoint]) => endpoint);
        return candidates.length === 1 ? candidates[0] : undefined;
    }, [context.api]);
    const verifyEndpoint = canonicalSiteVerifyEndpoint(detailEndpoint);
    const canManageConnector = context.permissions.includes('*') || context.permissions.includes('connector.manage');

    if (!canManageConnector || !detailEndpoint || !verifyEndpoint) return null;

    return (
        <AuthorizedSiteDetailsSaveTestControl
            context={context}
            detailEndpoint={detailEndpoint}
            verifyEndpoint={verifyEndpoint}
        />
    );
}

function AuthorizedSiteDetailsSaveTestControl({
    context,
    detailEndpoint,
    verifyEndpoint,
}: {
    context: FrontendContext;
    detailEndpoint: string;
    verifyEndpoint: string;
}) {
    const { locale } = useLocale();
    const details = useQuery({
        queryKey: ['canonical-site-details-save-test', context.tenant.slug, detailEndpoint],
        queryFn: () => apiRequest<SiteDetailsPayload>(detailEndpoint),
        retry: false,
    });

    const mutation = useMutation({
        mutationFn: () => mutateThenReconcile(
            () => apiRequest<HealthPayload>(verifyEndpoint, { method: 'POST' }),
            async () => {
                const refreshed = await details.refetch();
                if (refreshed.error) throw refreshed.error;
                if (!refreshed.data) throw new Error('Site verification completed but authoritative Site Details could not be reread.');
            },
        ),
    });

    const error = mutation.error;
    const errorMessage = error instanceof AuthoritativeReconciliationError
        ? error.message
        : error instanceof ApiError
            ? error.message
            : error instanceof Error
                ? error.message
                : null;

    return (
        <section
            className="toolbar-panel site-details-save-test"
            aria-label={locale === 'ar' ? 'حفظ واختبار اتصال WordPress' : 'Save and test WordPress connection'}
            data-canonical-operation={SITE_DETAILS_SAVE_TEST_OPERATION_ID}
        >
            <div>
                <span className="workspace-kicker">{locale === 'ar' ? 'بيانات الاتصال المحفوظة' : 'SAVED CONNECTION'}</span>
                <p>
                    {locale === 'ar'
                        ? 'يستخدم Laravel سر الموصل المحفوظ والمشفّر. لا يتم عرض أو إعادة إرسال كلمة مرور تطبيق من الواجهة.'
                        : 'Laravel uses the already-paired encrypted connector secret. No stored application password is exposed or echoed into the browser.'}
                </p>
                {details.data?.connection_status ? (
                    <small>{locale === 'ar' ? 'الحالة' : 'Status'}: {details.data.connection_status}</small>
                ) : null}
                {errorMessage ? <p role="alert">{errorMessage}</p> : null}
            </div>
            <button
                type="button"
                className="btn primary"
                disabled={mutation.isPending || details.isLoading}
                onClick={() => mutation.mutate()}
            >
                {mutation.isPending
                    ? (locale === 'ar' ? 'جارٍ الحفظ والاختبار…' : 'Saving & testing…')
                    : (locale === 'ar' ? 'حفظ واختبار' : 'Save & test')}
            </button>
        </section>
    );
}
