import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITE_DETAILS_PAIRING_OPERATION_ID,
    SiteDetailsPairingControl,
    canonicalExistingSitePairingEndpoint,
} from '../site-details-pairing-control';

const detailUrl = '/api/tenants/alpha/sites/17';
const tokenA = 'A'.repeat(64);
const tokenB = 'B'.repeat(64);

function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view', 'connector.manage'],
        connectors: [],
        capabilities: {},
        api: { 'sites.detail.17': detailUrl },
        actions: {},
    };
}

function response(body: unknown, status = 200) {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function renderControl(active = context()) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <SiteDetailsPairingControl context={active} />
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('Existing WordPress site pairing (#1438)', () => {
    it('issues a one-use token using the existing tenant site without creating another site', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(response({ id: 17, name: 'NOB' }))
            .mockResolvedValueOnce(response({ pairing_token: tokenA, expires_in: 600 }));
        vi.stubGlobal('fetch', fetchMock);
        renderControl();

        const issue = screen.getByRole('button', { name: 'Generate one-time pairing token' });
        expect(issue.closest('section')).toHaveAttribute('data-canonical-operation', SITE_DETAILS_PAIRING_OPERATION_ID);
        await waitFor(() => expect(issue).toBeEnabled());
        fireEvent.click(issue);

        expect(await screen.findByDisplayValue(tokenA)).toHaveAttribute('readonly');
        expect(screen.getByText(/Platform URL:/)).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls[0][0]).toBe(detailUrl);
        expect(fetchMock.mock.calls[1][0]).toBe(`${detailUrl}/pairing`);
        expect(fetchMock.mock.calls[1][1]).toMatchObject({ method: 'POST' });
        expect(fetchMock.mock.calls[1][1]).not.toHaveProperty('body');
        expect(JSON.stringify(fetchMock.mock.calls)).not.toContain('application_password');
        expect(JSON.stringify(fetchMock.mock.calls)).not.toContain(tokenA);
    });

    it('re-issues a fresh token and does not leave the previous token visible', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(response({ id: 17, name: 'NOB' }))
            .mockResolvedValueOnce(response({ pairing_token: tokenA, expires_in: 600 }))
            .mockResolvedValueOnce(response({ pairing_token: tokenB, expires_in: 600 }));
        vi.stubGlobal('fetch', fetchMock);
        renderControl();
        const issue = screen.getByRole('button', { name: 'Generate one-time pairing token' });
        await waitFor(() => expect(issue).toBeEnabled());

        fireEvent.click(issue);
        expect(await screen.findByDisplayValue(tokenA)).toBeInTheDocument();

        fireEvent.click(issue);
        expect(await screen.findByDisplayValue(tokenB)).toBeInTheDocument();
        expect(screen.queryByDisplayValue(tokenA)).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    it('removes the sensitive token when the server-provided expiry is reached', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(response({ id: 17, name: 'NOB' }))
            .mockResolvedValueOnce(response({ pairing_token: tokenA, expires_in: 0.2 })));
        renderControl();
        const issue = screen.getByRole('button', { name: 'Generate one-time pairing token' });
        await waitFor(() => expect(issue).toBeEnabled());
        fireEvent.click(issue);
        expect(await screen.findByDisplayValue(tokenA)).toBeInTheDocument();
        await waitFor(() => expect(screen.queryByDisplayValue(tokenA)).not.toBeInTheDocument());
    });

    it('does not issue a token when site ownership cannot be verified', async () => {
        const fetchMock = vi.fn().mockResolvedValueOnce(response({ message: 'Not Found' }, 404));
        vi.stubGlobal('fetch', fetchMock);
        renderControl();
        const issue = screen.getByRole('button', { name: 'Generate one-time pairing token' });
        await screen.findByRole('alert');
        expect(issue).toBeDisabled();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('fails closed on server rejection or malformed token response', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(response({ id: 17 }))
            .mockResolvedValueOnce(response({ message: 'Forbidden' }, 403));
        vi.stubGlobal('fetch', fetchMock);
        renderControl();
        const issue = screen.getByRole('button', { name: 'Generate one-time pairing token' });
        await waitFor(() => expect(issue).toBeEnabled());
        fireEvent.click(issue);
        await screen.findByRole('alert');
        expect(screen.queryByDisplayValue(tokenA)).not.toBeInTheDocument();
    });

    it('does not render for insufficient connector permissions or ambiguous/foreign site context', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const denied = context();
        denied.permissions = ['tenant.view', 'sites.view'];
        const { unmount } = renderControl(denied);
        expect(screen.queryByRole('button', { name: 'Generate one-time pairing token' })).not.toBeInTheDocument();
        unmount();

        const foreign = context();
        foreign.api = { 'sites.detail.17': '/api/tenants/beta/sites/17' };
        const other = renderControl(foreign);
        expect(screen.queryByRole('button', { name: 'Generate one-time pairing token' })).not.toBeInTheDocument();
        other.unmount();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('derives only exact tenant-scoped pairing paths bound to the authoritative site key', () => {
        expect(canonicalExistingSitePairingEndpoint(detailUrl, 'alpha', 'sites.detail.17')).toBe(`${detailUrl}/pairing`);
        expect(canonicalExistingSitePairingEndpoint(detailUrl, 'beta', 'sites.detail.17')).toBeNull();
        expect(canonicalExistingSitePairingEndpoint(detailUrl, 'alpha', 'sites.detail.18')).toBeNull();
        expect(canonicalExistingSitePairingEndpoint('https://evil.test' + detailUrl, 'alpha', 'sites.detail.17')).toBeNull();
        expect(canonicalExistingSitePairingEndpoint(detailUrl + '?site=18', 'alpha', 'sites.detail.17')).toBeNull();
        expect(canonicalExistingSitePairingEndpoint('/api/tenants/alpha/sites/../17', 'alpha', 'sites.detail.17')).toBeNull();
    });
});
