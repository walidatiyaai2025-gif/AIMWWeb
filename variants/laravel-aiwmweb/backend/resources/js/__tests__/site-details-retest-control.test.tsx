import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITE_DETAILS_RETEST_OPERATION_ID,
    SiteDetailsRetestControl,
    canonicalSiteDetailsRetestEndpoint,
} from '../site-details-retest-control';

function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view', 'connector.manage'],
        connectors: [],
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
                <SiteDetailsRetestControl context={activeContext} />
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('AIMW-BILL-D6B3460675 Site Details RetestAsync', () => {
    it('runs the real tenant-scoped recheck and rereads authoritative Site Details before completion', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 17, connection_status: 'connected' }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 41, classification: 'connected' }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 17, connection_status: 'connected', health_state: 'connected' }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        const button = await screen.findByRole('button', { name: 'Retest' });
        expect(button).toHaveAttribute('data-canonical-operation', SITE_DETAILS_RETEST_OPERATION_ID);
        expect(SITE_DETAILS_RETEST_OPERATION_ID).toBe('AIMW-BILL-D6B3460675');
        await waitFor(() => expect(button).toBeEnabled());

        fireEvent.click(button);

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));
        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/tenants/alpha/sites/17');
        expect(fetchMock.mock.calls[1]?.[0]).toBe('/api/tenants/alpha/sites/17/connection/recheck');
        expect(fetchMock.mock.calls[1]?.[1]).toMatchObject({ method: 'POST' });
        expect(fetchMock.mock.calls[2]?.[0]).toBe('/api/tenants/alpha/sites/17');
        expect(JSON.stringify(fetchMock.mock.calls)).not.toContain('password');
        expect(JSON.stringify(fetchMock.mock.calls)).not.toContain('encrypted_secret');
    });

    it('fails closed before hooks when connector.manage is absent', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const activeContext = context();
        activeContext.permissions = ['tenant.view', 'sites.view'];

        render(
            <LocaleProvider>
                <SiteDetailsRetestControl context={activeContext} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: 'Retest' })).not.toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('derives only the exact tenant-scoped recheck endpoint from Site Details', () => {
        expect(canonicalSiteDetailsRetestEndpoint('/api/tenants/alpha/sites/17')).toBe('/api/tenants/alpha/sites/17/connection/recheck');
        expect(canonicalSiteDetailsRetestEndpoint('/api/tenants/beta/sites/22')).toBe('/api/tenants/beta/sites/22/connection/recheck');
        expect(canonicalSiteDetailsRetestEndpoint('/api/tenants/alpha/sites/17?site=22')).toBeNull();
        expect(canonicalSiteDetailsRetestEndpoint('/api/tenants/alpha/sites/../22')).toBeNull();
        expect(canonicalSiteDetailsRetestEndpoint('https://foreign.example/api/tenants/alpha/sites/17')).toBeNull();
        expect(canonicalSiteDetailsRetestEndpoint(undefined)).toBeNull();
    });
});
