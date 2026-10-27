import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { BILLING_REACTIVATE_PAYPAL_OPERATION_ID, BillingReactivatePayPalControl, canonicalReactivationEndpoints } from '../billing-reactivate-paypal-control';
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
function renderControl(activeContext = context()) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return render(<QueryClientProvider client={client}><LocaleProvider><BillingReactivatePayPalControl context={activeContext} /></LocaleProvider></QueryClientProvider>);
}
afterEach(() => { vi.unstubAllGlobals(); vi.restoreAllMocks(); });

describe('AIMW-BILL-A8CBD94255 PayPal reactivation', () => {
    it('submits no caller-selected identity and rereads authoritative state', async () => {
        const before = { data: { state: 'SUSPENDED', can_reactivate: true } };
        const accepted = { data: { request_status: 'provider_accepted', state: 'SUSPENDED', provider_state_authoritative: true } };
        const reread = { data: { state: 'SUSPENDED', can_reactivate: true } };
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify(before), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify(accepted), { status: 202, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify(reread), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);
        renderControl();
        const button = await screen.findByRole('button', { name: 'Request reactivation' });
        expect(button).toHaveAttribute('data-canonical-operation', BILLING_REACTIVATE_PAYPAL_OPERATION_ID);
        fireEvent.click(button);
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));
        expect(fetchMock.mock.calls[1]?.[0]).toBe('/api/v1/tenants/alpha/billing/reactivate');
        expect(fetchMock.mock.calls[1]?.[1]).toMatchObject({ method: 'POST', body: JSON.stringify({}) });
        expect(JSON.stringify(fetchMock.mock.calls[1]?.[1])).not.toContain('subscription_id');
        expect(JSON.stringify(fetchMock.mock.calls[1]?.[1])).not.toContain('provider');
        expect(await screen.findByRole('status')).toHaveTextContent('Local state remains suspended');
    });

    it('does not render without billing.manage and validates tenant slug', () => {
        const fetchMock = vi.fn(); vi.stubGlobal('fetch', fetchMock);
        const active = context(); active.permissions = ['tenant.view', 'billing.view'];
        render(<LocaleProvider><BillingReactivatePayPalControl context={active} /></LocaleProvider>);
        expect(screen.queryByRole('button', { name: 'Request reactivation' })).not.toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(canonicalReactivationEndpoints('alpha/../beta')).toBeNull();
        expect(BILLING_REACTIVATE_PAYPAL_OPERATION_ID).toBe('AIMW-BILL-A8CBD94255');
    });
});
