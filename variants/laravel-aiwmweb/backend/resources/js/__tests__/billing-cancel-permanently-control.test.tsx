import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    BILLING_CANCEL_PERMANENTLY_OPERATION_ID,
    BillingCancelPermanentlyControl,
    canonicalPermanentCancellationEndpoints,
} from '../billing-cancel-permanently-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'billing.view', 'billing.manage'],
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
    };
}

function renderControl(activeContext = context()) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <BillingCancelPermanentlyControl context={activeContext} />
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('AIMW-BILL-9DD2652E1E permanent PayPal cancellation', () => {
    it('submits only the validated mode and reports provider acceptance only after authoritative reread', async () => {
        const before = { data: { state: 'ACTIVE', cancel_at_period_end: false, can_cancel_permanently: true } };
        const accepted = { data: { request_status: 'provider_accepted', state: 'ACTIVE', cancel_at_period_end: false, provider_state_authoritative: true } };
        const reread = { data: { state: 'ACTIVE', cancel_at_period_end: false, can_cancel_permanently: true } };
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify(before), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify(accepted), { status: 202, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify(reread), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        renderControl();

        const button = await screen.findByRole('button', { name: 'Permanently cancel PayPal' });
        expect(button).toHaveAttribute('data-canonical-operation', BILLING_CANCEL_PERMANENTLY_OPERATION_ID);
        expect(button.closest('section')).toHaveAttribute('data-canonical-operation', BILLING_CANCEL_PERMANENTLY_OPERATION_ID);
        fireEvent.click(button);

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));
        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/v1/tenants/alpha/billing/subscription');
        expect(fetchMock.mock.calls[1]?.[0]).toBe('/api/v1/tenants/alpha/billing/cancel');
        expect(fetchMock.mock.calls[1]?.[1]).toMatchObject({ method: 'POST', body: JSON.stringify({ mode: 'permanent_provider' }) });
        expect(JSON.stringify(fetchMock.mock.calls[1]?.[1])).not.toContain('tenant_id');
        expect(JSON.stringify(fetchMock.mock.calls[1]?.[1])).not.toContain('subscription_id');
        expect(JSON.stringify(fetchMock.mock.calls[1]?.[1])).not.toContain('provider_subscription');
        expect(fetchMock.mock.calls[2]?.[0]).toBe('/api/v1/tenants/alpha/billing/subscription');
        expect(await screen.findByRole('status')).toHaveTextContent('PayPal accepted the cancellation request');
        expect(screen.getByRole('button', { name: 'Permanently cancel PayPal' })).toBeDisabled();
    });

    it('fails closed when the authoritative reread contradicts provider-accepted semantics', async () => {
        const before = { data: { state: 'ACTIVE', cancel_at_period_end: false, can_cancel_permanently: true } };
        const accepted = { data: { request_status: 'provider_accepted', state: 'ACTIVE', cancel_at_period_end: false, provider_state_authoritative: true } };
        const contradictory = { data: { state: 'ACTIVE', cancel_at_period_end: true, can_cancel_permanently: true } };
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify(before), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify(accepted), { status: 202, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify(contradictory), { status: 200, headers: { 'content-type': 'application/json' } })));
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        renderControl();
        fireEvent.click(await screen.findByRole('button', { name: 'Permanently cancel PayPal' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('changed local cancellation state before provider confirmation');
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('does not render or query without both billing.view and billing.manage', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const activeContext = context();
        activeContext.permissions = ['tenant.view', 'billing.view'];

        render(
            <LocaleProvider>
                <BillingCancelPermanentlyControl context={activeContext} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: 'Permanently cancel PayPal' })).not.toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('derives only a canonical same-origin tenant path from the active tenant slug', () => {
        expect(canonicalPermanentCancellationEndpoints('alpha')).toEqual({
            subscription: '/api/v1/tenants/alpha/billing/subscription',
            cancel: '/api/v1/tenants/alpha/billing/cancel',
        });
        expect(canonicalPermanentCancellationEndpoints('alpha/../beta')).toBeNull();
        expect(canonicalPermanentCancellationEndpoints('https://foreign.example')).toBeNull();
        expect(BILLING_CANCEL_PERMANENTLY_OPERATION_ID).toBe('AIMW-BILL-9DD2652E1E');
    });
});
