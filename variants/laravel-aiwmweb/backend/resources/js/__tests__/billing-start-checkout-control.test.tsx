import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    BILLING_START_CHECKOUT_OPERATION_ID,
    BillingStartCheckoutControl,
    canonicalCheckoutEndpoints,
    checkoutApprovalUrl,
} from '../billing-start-checkout-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'billing.view', 'billing.manage'],
        connectors: [], capabilities: {}, api: {}, actions: {},
    };
}

function renderControl(activeContext = context(), navigate = vi.fn()) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return {
        navigate,
        ...render(
            <QueryClientProvider client={client}>
                <LocaleProvider>
                    <BillingStartCheckoutControl
                        context={activeContext}
                        navigate={navigate}
                        idempotencyKeyFactory={() => 'billing-checkout-test-key-0001'}
                    />
                </LocaleProvider>
            </QueryClientProvider>,
        ),
    };
}

afterEach(() => { vi.unstubAllGlobals(); vi.restoreAllMocks(); });

describe('AIMW-BILL-8DD8F167D3 billing checkout', () => {
    it('creates a tenant-scoped idempotent checkout and navigates only to an HTTPS approval URL', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);
            if (url.endsWith('/billing/subscription')) {
                return new Response(JSON.stringify({ data: { state: 'TRIALING', plan: { code: 'free-trial', name: 'Trial', price_minor: 0, currency: 'USD', billing_interval: 'monthly', checkout_available: false } } }), { status: 200, headers: { 'content-type': 'application/json' } });
            }
            if (url === '/api/v1/billing/plans') {
                return new Response(JSON.stringify({ data: [{ code: 'pro', name: 'Pro', localized_name: 'Pro', price_minor: 4900, currency: 'USD', billing_interval: 'monthly', checkout_available: true }] }), { status: 200, headers: { 'content-type': 'application/json' } });
            }
            if (url.endsWith('/billing/checkout')) {
                expect(init?.method).toBe('POST');
                expect(init?.body).toBe(JSON.stringify({ plan_code: 'pro' }));
                expect(new Headers(init?.headers).get('Idempotency-Key')).toBe('billing-checkout-test-key-0001');
                expect(JSON.stringify(init)).not.toContain('tenant_id');
                expect(JSON.stringify(init)).not.toContain('user_id');
                expect(JSON.stringify(init)).not.toContain('provider_subscription');
                return new Response(JSON.stringify({ data: { approval_url: 'https://www.paypal.com/checkoutnow?token=abc', status: 'PENDING_PROVIDER_CONFIRMATION' } }), { status: 201, headers: { 'content-type': 'application/json' } });
            }
            throw new Error(`Unexpected request: ${url}`);
        });
        vi.stubGlobal('fetch', fetchMock);

        const { navigate } = renderControl();
        const button = await screen.findByRole('button', { name: 'Continue with PayPal' });
        expect(button).toHaveAttribute('data-canonical-operation', BILLING_START_CHECKOUT_OPERATION_ID);
        await waitFor(() => expect(button).toBeEnabled());

        fireEvent.click(button);

        await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => String(url).endsWith('/billing/checkout'))).toBe(true));
        await waitFor(() => expect(navigate).toHaveBeenCalledWith('https://www.paypal.com/checkoutnow?token=abc'));
    });

    it('does not expose the mutation without billing.manage and rejects invalid tenant or unsafe redirects', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const active = context();
        active.permissions = ['tenant.view', 'billing.view'];
        renderControl(active);
        expect(screen.queryByRole('button', { name: 'Continue with PayPal' })).toBeDisabled();
        expect(canonicalCheckoutEndpoints('alpha/../beta')).toBeNull();
        expect(checkoutApprovalUrl('http://paypal.example.test/checkout')).toBeNull();
        expect(BILLING_START_CHECKOUT_OPERATION_ID).toBe('AIMW-BILL-8DD8F167D3');
    });
});
