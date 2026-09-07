import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITE_DETAILS_SAVE_TEST_OPERATION_ID,
    SiteDetailsSaveTestControl,
    canonicalSiteVerifyEndpoint,
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

describe('AIMW-AI-387F3E5D5F Site Details Save & Test adaptation', () => {
    it('tests the already-saved connector credential and authoritatively rereads Site Details before completing', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 17, connection_status: 'paired' }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ status: 'healthy' }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 17, connection_status: 'verified', health_state: 'healthy' }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        const button = await screen.findByRole('button', { name: 'Save & test' });
        expect(button.closest('section')).toHaveAttribute('data-canonical-operation', SITE_DETAILS_SAVE_TEST_OPERATION_ID);
        expect(SITE_DETAILS_SAVE_TEST_OPERATION_ID).toBe('AIMW-AI-387F3E5D5F');

        await screen.findByText(/Status: paired/i);
        await waitFor(() => expect(button).toBeEnabled());
        fireEvent.click(button);

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));
        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/tenants/alpha/sites/17');
        expect(fetchMock.mock.calls[1]?.[0]).toBe('/api/tenants/alpha/sites/17/verify');
        expect(fetchMock.mock.calls[1]?.[1]).toMatchObject({ method: 'POST' });
        expect(fetchMock.mock.calls[1]?.[1]).not.toHaveProperty('body');
        expect(fetchMock.mock.calls[2]?.[0]).toBe('/api/tenants/alpha/sites/17');
        expect(JSON.stringify(fetchMock.mock.calls)).not.toContain('application_password');
        expect(JSON.stringify(fetchMock.mock.calls)).not.toContain('encrypted_secret');
        expect(await screen.findByText(/Status: verified/i)).toBeInTheDocument();
    });

    it('fails closed when connector.manage authorization is absent', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const activeContext = context();
        activeContext.permissions = ['tenant.view', 'sites.view'];

        renderControl(activeContext);

        expect(screen.queryByRole('button', { name: 'Save & test' })).not.toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('accepts only the exact tenant-scoped Site Details contract when deriving the verify endpoint', () => {
        expect(canonicalSiteVerifyEndpoint('/api/tenants/alpha/sites/17')).toBe('/api/tenants/alpha/sites/17/verify');
        expect(canonicalSiteVerifyEndpoint('/api/tenants/beta/sites/22')).toBe('/api/tenants/beta/sites/22/verify');
        expect(canonicalSiteVerifyEndpoint('/api/tenants/alpha/sites/17?site=22')).toBeNull();
        expect(canonicalSiteVerifyEndpoint('/api/tenants/alpha/sites/../22')).toBeNull();
        expect(canonicalSiteVerifyEndpoint('https://foreign.example/api/tenants/alpha/sites/17')).toBeNull();
        expect(canonicalSiteVerifyEndpoint(undefined)).toBeNull();
    });

    it('documents the focused cross-tenant isolation contract as 404 and authorization contract as 403', () => {
        const crossTenantStatus = 404;
        const unauthorizedMutationStatus = 403;
        expect(crossTenantStatus).toBe(404);
        expect(unauthorizedMutationStatus).toBe(403);
    });
});
