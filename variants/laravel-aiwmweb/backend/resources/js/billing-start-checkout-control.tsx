import React, { useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ApiError, apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const BILLING_START_CHECKOUT_OPERATION_ID = 'AIMW-BILL-8DD8F167D3';

type BillingPlan = {
    code: string;
    name: string;
    localized_name?: string | null;
    price_minor: number;
    currency: string;
    billing_interval: string;
    checkout_available: boolean;
};

type SubscriptionSnapshot = {
    state: string;
    plan: BillingPlan | null;
};

type SubscriptionResponse = { data: SubscriptionSnapshot | null };
type PlansResponse = { data: BillingPlan[] };
type CheckoutResponse = {
    data: {
        approval_url: string;
        status: 'PENDING_PROVIDER_CONFIRMATION';
    };
};

export function canonicalCheckoutEndpoints(tenantSlug: string): { subscription: string; plans: string; checkout: string } | null {
    if (!/^[a-z0-9][a-z0-9-]{0,62}$/.test(tenantSlug)) return null;
    const base = `/api/v1/tenants/${encodeURIComponent(tenantSlug)}/billing`;
    return {
        subscription: `${base}/subscription`,
        plans: '/api/v1/billing/plans',
        checkout: `${base}/checkout`,
    };
}

export function checkoutApprovalUrl(value: string): string | null {
    try {
        const parsed = new URL(value, window.location.origin);
        return parsed.protocol === 'https:' ? parsed.toString() : null;
    } catch {
        return null;
    }
}

function defaultIdempotencyKey(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return `billing-checkout-${crypto.randomUUID()}`;
    }
    return `billing-checkout-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export function BillingStartCheckoutControl({
    context,
    navigate = (url) => window.location.assign(url),
    idempotencyKeyFactory = defaultIdempotencyKey,
}: {
    context: FrontendContext;
    navigate?: (url: string) => void;
    idempotencyKeyFactory?: () => string;
}) {
    const mayView = context.permissions.includes('*') || context.permissions.includes('billing.view');
    const mayManage = context.permissions.includes('*') || context.permissions.includes('billing.manage');
    const endpoints = canonicalCheckoutEndpoints(context.tenant.slug);

    if (!mayView || !endpoints) return null;

    return (
        <AuthorizedCheckoutControl
            context={context}
            endpoints={endpoints}
            mayManage={mayManage}
            navigate={navigate}
            idempotencyKeyFactory={idempotencyKeyFactory}
        />
    );
}

function AuthorizedCheckoutControl({
    context,
    endpoints,
    mayManage,
    navigate,
    idempotencyKeyFactory,
}: {
    context: FrontendContext;
    endpoints: { subscription: string; plans: string; checkout: string };
    mayManage: boolean;
    navigate: (url: string) => void;
    idempotencyKeyFactory: () => string;
}) {
    const { locale } = useLocale();
    const [selectedPlanCode, setSelectedPlanCode] = useState('');
    const subscription = useQuery({
        queryKey: ['billing-subscription', context.tenant.slug, endpoints.subscription],
        queryFn: () => apiRequest<SubscriptionResponse>(endpoints.subscription),
        retry: false,
        staleTime: 0,
    });
    const plans = useQuery({
        queryKey: ['billing-plans', endpoints.plans],
        queryFn: () => apiRequest<PlansResponse>(endpoints.plans),
        retry: false,
        staleTime: 0,
    });

    const eligiblePlans = useMemo(
        () => (plans.data?.data ?? []).filter((plan) => plan.checkout_available && plan.code !== 'free-trial'),
        [plans.data],
    );
    const current = subscription.data?.data ?? null;
    const checkoutStateAllowed = current === null || ['TRIALING', 'EXPIRED', 'CANCELLED'].includes(current.state);
    const preferredPlanCode = current?.plan?.checkout_available && current.plan.code !== 'free-trial'
        ? current.plan.code
        : eligiblePlans[0]?.code ?? '';
    const effectivePlanCode = selectedPlanCode || preferredPlanCode;

    const checkout = useMutation({
        mutationFn: async () => {
            if (!mayManage) throw new Error(locale === 'ar' ? 'لا تملك صلاحية إدارة الفوترة.' : 'Billing management permission is required.');
            if (!checkoutStateAllowed) throw new Error(locale === 'ar' ? 'استخدم تغيير الخطة للاشتراك المدفوع الحالي.' : 'Use plan change for the current paid subscription.');
            if (!effectivePlanCode) throw new Error(locale === 'ar' ? 'لا توجد خطة PayPal متاحة للدفع.' : 'No PayPal checkout plan is available.');

            const result = await apiRequest<CheckoutResponse>(endpoints.checkout, {
                method: 'POST',
                headers: { 'Idempotency-Key': idempotencyKeyFactory() },
                body: JSON.stringify({ plan_code: effectivePlanCode }),
            });
            const approval = checkoutApprovalUrl(result.data.approval_url);
            if (!approval) {
                throw new Error(locale === 'ar' ? 'أعاد مزود الدفع رابط موافقة غير صالح.' : 'The payment provider returned an invalid approval URL.');
            }
            return { result, approval };
        },
        onSuccess: ({ approval }) => navigate(approval),
    });

    const error = checkout.error ?? subscription.error ?? plans.error;
    const errorMessage = error instanceof ApiError ? error.message : error instanceof Error ? error.message : null;
    const disabled = !mayManage || !checkoutStateAllowed || !effectivePlanCode || checkout.isPending || subscription.isLoading || plans.isLoading;

    return (
        <section
            className="toolbar-panel billing-start-checkout"
            aria-label={locale === 'ar' ? 'بدء دفع اشتراك PayPal' : 'Start PayPal subscription checkout'}
            data-canonical-operation={BILLING_START_CHECKOUT_OPERATION_ID}
        >
            <div>
                <span className="workspace-kicker">{locale === 'ar' ? 'الدفع عبر PayPal' : 'PAYPAL CHECKOUT'}</span>
                <p>
                    {locale === 'ar'
                        ? 'ينشئ Laravel جلسة الاشتراك من الخادم. الرجوع من PayPal للتنقل فقط ولا يُعد إثبات دفع حتى تؤكد مصالحة المزود الحالة.'
                        : 'Laravel creates the subscription session server-side. Returning from PayPal is navigation only and is not payment evidence until provider reconciliation confirms the state.'}
                </p>
                {eligiblePlans.length > 1 ? (
                    <label>
                        <span>{locale === 'ar' ? 'الخطة' : 'Plan'}</span>
                        <select
                            aria-label={locale === 'ar' ? 'خطة الدفع' : 'Checkout plan'}
                            value={effectivePlanCode}
                            disabled={checkout.isPending}
                            onChange={(event) => setSelectedPlanCode(event.target.value)}
                        >
                            {eligiblePlans.map((plan) => (
                                <option key={plan.code} value={plan.code}>
                                    {(plan.localized_name || plan.name || plan.code)} · {(plan.price_minor / 100).toFixed(2)} {plan.currency}
                                </option>
                            ))}
                        </select>
                    </label>
                ) : null}
                {!checkoutStateAllowed ? (
                    <p role="status">{locale === 'ar' ? 'الاشتراك المدفوع الحالي يستخدم مسار تغيير الخطة بدل checkout جديد.' : 'The current paid subscription uses the plan-change flow instead of a new checkout.'}</p>
                ) : null}
                {errorMessage ? <p role="alert">{errorMessage}</p> : null}
            </div>
            <button
                type="button"
                className="btn"
                data-canonical-operation={BILLING_START_CHECKOUT_OPERATION_ID}
                disabled={disabled}
                aria-busy={checkout.isPending ? 'true' : 'false'}
                onClick={() => checkout.mutate()}
            >
                {checkout.isPending
                    ? (locale === 'ar' ? 'جارٍ إنشاء جلسة PayPal…' : 'Creating PayPal session…')
                    : (locale === 'ar' ? 'متابعة الدفع عبر PayPal' : 'Continue with PayPal')}
            </button>
        </section>
    );
}
