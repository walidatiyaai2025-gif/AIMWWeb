import React from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const BILLING_CANCEL_PERMANENTLY_OPERATION_ID = 'AIMW-BILL-9DD2652E1E';

type SubscriptionSnapshot = {
    state: string;
    cancel_at_period_end: boolean;
    can_cancel_permanently: boolean;
};

type SubscriptionResponse = { data: SubscriptionSnapshot | null };
type CancellationResponse = {
    data: {
        request_status: 'provider_accepted' | 'provider_confirmed';
        state: string;
        cancel_at_period_end: boolean;
        provider_state_authoritative: true;
    };
};

export function canonicalPermanentCancellationEndpoints(tenantSlug: string): { subscription: string; cancel: string } | null {
    if (!/^[a-z0-9][a-z0-9-]{0,62}$/.test(tenantSlug)) {
        return null;
    }

    const base = `/api/v1/tenants/${encodeURIComponent(tenantSlug)}/billing`;
    return { subscription: `${base}/subscription`, cancel: `${base}/cancel` };
}

export function BillingCancelPermanentlyControl({ context }: { context: FrontendContext }) {
    const mayView = context.permissions.includes('*') || context.permissions.includes('billing.view');
    const mayManage = context.permissions.includes('*') || context.permissions.includes('billing.manage');
    const endpoints = canonicalPermanentCancellationEndpoints(context.tenant.slug);

    if (!mayView || !mayManage || !endpoints) {
        return null;
    }

    return <AuthorizedPermanentCancellationControl context={context} endpoints={endpoints} />;
}

function AuthorizedPermanentCancellationControl({
    context,
    endpoints,
}: {
    context: FrontendContext;
    endpoints: { subscription: string; cancel: string };
}) {
    const { locale } = useLocale();
    const queryClient = useQueryClient();
    const queryKey = ['billing-subscription', context.tenant.slug, endpoints.subscription] as const;
    const subscription = useQuery({
        queryKey,
        queryFn: () => apiRequest<SubscriptionResponse>(endpoints.subscription),
        retry: false,
        staleTime: 0,
    });

    const cancellation = useMutation({
        mutationFn: async () => {
            const before = subscription.data?.data;
            if (!before?.can_cancel_permanently) {
                throw new Error(locale === 'ar' ? 'الإلغاء النهائي غير متاح لهذه الحالة.' : 'Permanent cancellation is unavailable for this subscription state.');
            }

            const command = await apiRequest<CancellationResponse>(endpoints.cancel, {
                method: 'POST',
                body: JSON.stringify({ mode: 'permanent_provider' }),
            });
            const reread = await apiRequest<SubscriptionResponse>(endpoints.subscription);
            const authoritative = reread.data;
            if (!authoritative) {
                throw new Error(locale === 'ar' ? 'تعذر إعادة قراءة الاشتراك بعد الطلب.' : 'The subscription could not be authoritatively reread after the request.');
            }

            if (command.data.request_status === 'provider_confirmed') {
                if (authoritative.state !== 'CANCELLED') {
                    throw new Error(locale === 'ar' ? 'لم تؤكد إعادة القراءة حالة الإلغاء.' : 'The authoritative reread did not confirm cancellation.');
                }
            } else if (authoritative.state !== 'CANCELLED' && authoritative.cancel_at_period_end !== before.cancel_at_period_end) {
                throw new Error(locale === 'ar' ? 'غيّر الخادم الحالة المحلية قبل تأكيد المزود.' : 'The server changed local cancellation state before provider confirmation.');
            }

            return { command, reread };
        },
        onSuccess: ({ reread }) => {
            queryClient.setQueryData<SubscriptionResponse>(queryKey, reread);
        },
    });

    const current = subscription.data?.data;
    if (!current?.can_cancel_permanently && !cancellation.data) {
        return null;
    }

    const error = cancellation.error ?? subscription.error;
    const errorMessage = error instanceof ApiError
        ? error.message
        : error instanceof Error
            ? error.message
            : null;
    const confirmed = cancellation.data?.reread.data?.state === 'CANCELLED';
    const accepted = cancellation.data?.command.data.request_status === 'provider_accepted';

    const requestCancellation = () => {
        if (cancellation.isPending || cancellation.data) {
            return;
        }
        const confirmedByUser = window.confirm(locale === 'ar'
            ? 'هل تريد إلغاء اشتراك PayPal نهائيًا؟ لا يمكن التراجع عن طلب الإلغاء بعد إرساله إلى PayPal.'
            : 'Permanently cancel the PayPal subscription? The cancellation request cannot be reversed after it is sent to PayPal.');
        if (confirmedByUser) {
            cancellation.mutate();
        }
    };

    return (
        <section
            className="toolbar-panel billing-permanent-cancellation"
            aria-label={locale === 'ar' ? 'الإلغاء النهائي لاشتراك PayPal' : 'Permanent PayPal cancellation'}
            data-canonical-operation={BILLING_CANCEL_PERMANENTLY_OPERATION_ID}
        >
            <div>
                <span className="workspace-kicker">{locale === 'ar' ? 'إلغاء PayPal النهائي' : 'PERMANENT PAYPAL CANCELLATION'}</span>
                <p>
                    {locale === 'ar'
                        ? 'يرسل الطلب إلى PayPal من الخادم. لا تتحول الحالة المحلية إلى ملغاة إلا بعد مصالحة موثوقة من المزود.'
                        : 'The request is sent to PayPal server-side. Local state is not reported as cancelled until authoritative provider reconciliation confirms it.'}
                </p>
                {accepted && !confirmed ? (
                    <p role="status">{locale === 'ar' ? 'قبل PayPal طلب الإلغاء. الحالة المحلية لم تُعتبر ملغاة بعد.' : 'PayPal accepted the cancellation request. Local state is not yet reported as cancelled.'}</p>
                ) : null}
                {confirmed ? (
                    <p role="status">{locale === 'ar' ? 'أكدت إعادة القراءة الموثوقة أن الاشتراك ملغى.' : 'The authoritative reread confirms the subscription is cancelled.'}</p>
                ) : null}
                {errorMessage ? <p role="alert">{errorMessage}</p> : null}
            </div>
            <button
                type="button"
                className="btn danger"
                data-canonical-operation={BILLING_CANCEL_PERMANENTLY_OPERATION_ID}
                disabled={cancellation.isPending || Boolean(cancellation.data)}
                onClick={requestCancellation}
            >
                {cancellation.isPending
                    ? (locale === 'ar' ? 'جارٍ إرسال الطلب…' : 'Sending cancellation request…')
                    : (locale === 'ar' ? 'إلغاء PayPal نهائيًا' : 'Permanently cancel PayPal')}
            </button>
        </section>
    );
}
