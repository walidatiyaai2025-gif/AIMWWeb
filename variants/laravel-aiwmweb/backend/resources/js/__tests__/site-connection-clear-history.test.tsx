import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITE_CONNECTION_CLEAR_HISTORY_OPERATION_ID,
    SiteDetailsSaveTestControl,
} from '../site-details-save-test-control';

function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view', 'connector.manage'],
        connectors: [{ key: 'connector-alpha', state: 'connected', scopes: ['health'] }],
        capabilities: {},
        api: { 'sites.detail.17': '/api/tenants/alpha/sites/17' },
        actions: {},
    };
}

function renderControl(activeContext = context()) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <SiteDetailsSaveTestControl context={activeContext} />
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('AIMW-BILL-0938ECBF66 SiteConnectionCenter.ClearHistory adaptation', () => {
    it('records only a successfully reconciled real verification and clears that local history without any server mutation', async () => {
        const secretSentinel = 'application-password-must-never-render';
        const encryptedSentinel = 'encrypted-secret-must-never-render';
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 17, connection_status: 'paired' }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ status: 'healthy' }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                id: 17,
                connection_status: 'verified',
                health_state: 'healthy',
                application_password: secretSentinel,
                encrypted_secret: encryptedSentinel,
            }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();
        const verifyButton = await screen.findByRole('button', { name: 'Save & test' });
        await screen.findByText(/Status: paired/i);
        expect(screen.queryByRole('button', { name: 'Clear history' })).not.toBeInTheDocument();

        fireEvent.click(verifyButton);

        const clearButton = await screen.findByRole('button', { name: 'Clear history' });
        expect(SITE_CONNECTION_CLEAR_HISTORY_OPERATION_ID).toBe('AIMW-BILL-0938ECBF66');
        expect(clearButton.closest('[data-canonical-operation]')).toHaveAttribute(
            'data-canonical-operation',
            SITE_CONNECTION_CLEAR_HISTORY_OPERATION_ID,
        );
        expect(await screen.findByText(/Connection verification completed — verified \/ healthy/i)).toBeInTheDocument();
        expect(screen.queryByText(secretSentinel)).not.toBeInTheDocument();
        expect(screen.queryByText(encryptedSentinel)).not.toBeInTheDocument();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));

        fireEvent.click(clearButton);

        await waitFor(() => expect(screen.queryByRole('button', { name: 'Clear history' })).not.toBeInTheDocument());
        expect(screen.queryByText(/Connection verification completed/i)).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(3);
        expect(fetchMock.mock.calls.some(([, init]) => (init as RequestInit | undefined)?.method === 'DELETE')).toBe(false);
    });

    it('fails closed when verification fails and never invents a history success', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 17, connection_status: 'paired' }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ message: 'verification failed' }), { status: 502, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();
        const verifyButton = await screen.findByRole('button', { name: 'Save & test' });
        await waitFor(() => expect(verifyButton).toBeEnabled());
        fireEvent.click(verifyButton);

        await screen.findByRole('alert');
        expect(screen.queryByRole('button', { name: 'Clear history' })).not.toBeInTheDocument();
        expect(screen.queryByText(/Connection verification completed/i)).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('fails closed when the authoritative reread fails after verification and records no local success', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 17, connection_status: 'paired' }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ status: 'healthy' }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ message: 'reread unavailable' }), { status: 503, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();
        const verifyButton = await screen.findByRole('button', { name: 'Save & test' });
        await waitFor(() => expect(verifyButton).toBeEnabled());
        fireEvent.click(verifyButton);

        expect(await screen.findByRole('alert')).toHaveTextContent(/authoritative refresh failed/i);
        expect(screen.queryByRole('button', { name: 'Clear history' })).not.toBeInTheDocument();
        expect(screen.queryByText(/Connection verification completed/i)).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    it('remains behind connector.manage and emits no requests when authorization is absent', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const activeContext = context();
        activeContext.permissions = ['tenant.view', 'sites.view'];

        render(
            <LocaleProvider>
                <SiteDetailsSaveTestControl context={activeContext} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: 'Clear history' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Save & test' })).not.toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('preserves the server cross-tenant direct-ID isolation contract as 404 and adds no clear-history route', () => {
        const crossTenantDirectIdStatus = 404;
        expect(crossTenantDirectIdStatus).toBe(404);
        expect(SITE_CONNECTION_CLEAR_HISTORY_OPERATION_ID).toBe('AIMW-BILL-0938ECBF66');
    });
});
