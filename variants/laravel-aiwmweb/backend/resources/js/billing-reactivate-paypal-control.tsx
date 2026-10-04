import React from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const BILLING_REACTIVATE_PAYPAL_OPERATION_ID = 'AIMW-BILL-A8CBD94255';

type SubscriptionSnapshot = {
    state: string;
    can_reactivate: boolean;
};
type SubscriptionResponse = { data: SubscriptionSnapshot | null };
type ReactivationResponse = {
    data: {
        request_status: 'provider_accepted' | 'provider_confirmed';
        state: string;
        provider_state_authoritative: true;
    };
};

export function canonicalReactivationEndpoints(tenantSlug: string): { subscription: string; reactivate: string } | null {
    if (!/^[a-z0-9][a-z0-9-]{0,62}$/.test(tenantSlug)) return null;
    const base = `/api/v1/tenants/${encodeURIComponent(tenantSlug)}/billing`;
    return { subscription: `${base}/subscription`, reactivate: `${base}/reactivate` };
}

export function BillingReactivatePayPalControl({ context }: { context: FrontendContext }) {
    const mayView = context.permissions.includes('*') || context.permissions.includes('billing.view');
    const mayManage = context.permissions.includes('*') || context.permissions.includes('billing.manage');
    const endpoints = canonicalReactivationEndpoints(context.tenant.slug);
    if (!mayView || !mayManage || !endpoints) return null;
    return <AuthorizedReactivationControl context={context} endpoints={endpoints} />;
}

function AuthorizedReactivationControl({ context, endpoints }: { context: FrontendContext; endpoints: { subscription: string; reactivate: string } }) {
    const { locale } = useLocale();
    const queryClient = useQueryClient();
    const queryKey = ['billing-subscription', context.tenant.slug, endpoints.subscription] as const;
    const subscription = useQuery({
        queryKey,
        queryFn: () => apiRequest<SubscriptionResponse>(endpoints.subscription),
        retry: false,
        staleTime: 0,
    });
    const reactivation = useMutation({
        mutationFn: async () => {
            const before = subscription.data?.data;
            if (!before?.can_reactivate || before.state !== 'SUSPENDED') {
                throw new Error(locale === 'ar' ? 'إعادة التفعيل متاحة فقط للاشتراك المعلق.' : 'Reactivation is available only for a suspended subscription.');
            }
            const command = await apiRequest<ReactivationResponse>(endpoints.reactivate, { method: 'POST', body: JSON.stringify({}) });
            const reread = await apiRequest<SubscriptionResponse>(endpoints.subscription);
            if (!reread.data) {
                throw new Error(locale === 'ar' ? 'تعذر إعادة قراءة الاشتراك بعد الطلب.' : 'The subscription could not be authoritatively reread after the request.');
            }
            if (command.data.request_status === 'provider_confirmed' && reread.data.state !== 'ACTIVE') {
                throw new Error(locale === 'ar' ? 'لم تؤكد إعادة القراءة حالة التفعيل.' : 'The authoritative reread did not confirm activation.');
            }
            if (command.data.request_status === 'provider_accepted' && reread.data.state !== 'SUSPENDED' && reread.data.state !== 'ACTIVE') {
                throw new Error(locale === 'ar' ? 'أعاد الخادم حالة غير موثوقة بعد الطلب.' : 'The server returned a non-authoritative state after reactivation request.');
            }
            return { command, reread };
        },
        onSuccess: ({ reread }) => queryClient.setQueryData<SubscriptionResponse>(queryKey, reread),
    });

    const current = subscription.data?.data;
    if (!current?.can_reactivate && !reactivation.data) return null;

    const error = reactivation.error ?? subscription.error;
    const errorMessage = error instanceof ApiError ? error.message : error instanceof Error ? error.message : null;
    const confirmed = reactivation.data?.reread.data?.state === 'ACTIVE';
    const accepted = reactivation.data?.command.data.request_status === 'provider_accepted';

    return (
        <section className="toolbar-panel billing-paypal-reactivation" data-canonical-operation={BILLING_REACTIVATE_PAYPAL_OPERATION_ID}>
            <div>
                <span className="workspace-kicker">{locale === 'ar' ? 'إعادة تفعيل PayPal' : 'PAYPAL REACTIVATION'}</span>
                <p>{locale === 'ar' ? 'يرسل الطلب من الخادم وتظل الحالة معلقة حتى تؤكد مصالحة PayPal التفعيل.' : 'The server submits the request while local state remains suspended until PayPal reconciliation confirms activation.'}</p>
                {accepted && !confirmed ? <p role="status">{locale === 'ar' ? 'قبل PayPal طلب إعادة التفعيل. الحالة المحلية ما زالت معلقة حتى تأكيد المزود.' : 'PayPal accepted the reactivation request. Local state remains suspended until provider confirmation.'}</p> : null}
                {confirmed ? <p role="status">{locale === 'ar' ? 'أكدت إعادة القراءة الموثوقة أن الاشتراك نشط.' : 'The authoritative reread confirms the subscription is active.'}</p> : null}
                {errorMessage ? <p role="alert">{errorMessage}</p> : null}
            </div>
            <button type="button" className="btn" data-canonical-operation={BILLING_REACTIVATE_PAYPAL_OPERATION_ID} disabled={reactivation.isPending || Boolean(reactivation.data)} onClick={() => reactivation.mutate()}>
                {reactivation.isPending ? (locale === 'ar' ? 'جارٍ إرسال الطلب…' : 'Sending reactivation request…') : (locale === 'ar' ? 'طلب إعادة التفعيل' : 'Request reactivation')}
            </button>
        </section>
    );
}
