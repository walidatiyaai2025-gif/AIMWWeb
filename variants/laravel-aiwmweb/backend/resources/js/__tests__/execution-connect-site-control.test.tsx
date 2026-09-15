import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import {
    EXECUTION_CONNECT_SITE_OPERATION_ID,
    ExecutionConnectSiteControl,
} from '../execution-connect-site-control';
import { LocaleProvider } from '../i18n';

function context(overrides: Partial<FrontendContext> = {}): FrontendContext {
    return {
        user: { id: 1, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['execution.view', 'sites.manage'],
        connectors: [],
        capabilities: {},
        api: { execution: '/api/tenants/alpha/executions', sites: '/api/tenants/alpha/sites' },
        actions: {},
        ...overrides,
    };
}

function jsonResponse(payload: unknown, status = 200) {
    return new Response(JSON.stringify(payload), {
        status,
        headers: { 'content-type': 'application/json' },
    });
}

function renderControl(value = context(), locale: 'en' | 'ar' = 'en') {
    window.localStorage.setItem('aiwm.locale', locale);
    return render(
        <LocaleProvider>
            <ExecutionConnectSiteControl context={value} />
        </LocaleProvider>,
    );
}

afterEach(() => {
    window.localStorage.removeItem('aiwm.locale');
    vi.unstubAllGlobals();
});

describe(`${EXECUTION_CONNECT_SITE_OPERATION_ID} Execution Center connect-site empty state`, () => {
    it('renders the canonical tenant-aware destination only after the authoritative tenant sites read proves there are no sites', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse([]));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        expect(screen.queryByRole('link', { name: 'Connect site' })).not.toBeInTheDocument();
        const link = await screen.findByRole('link', { name: 'Connect site' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites/connect');
        expect(link).toHaveAttribute('data-canonical-operation', EXECUTION_CONNECT_SITE_OPERATION_ID);
        expect(screen.getByText('No sites in your account')).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/api/tenants/alpha/sites');
    });

    it('does not render the control when the tenant already owns a site', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse([{ id: 12, name: 'Existing Site' }])));

        renderControl();

        await waitFor(() => expect(screen.queryByRole('link', { name: 'Connect site' })).not.toBeInTheDocument());
    });

    it('fails closed without execution view or sites manage permission and performs no sites read', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        const noExecutionView = renderControl(context({ permissions: ['sites.manage'] }));
        await waitFor(() => expect(screen.queryByRole('link', { name: 'Connect site' })).not.toBeInTheDocument());
        noExecutionView.unmount();

        const noSiteManage = renderControl(context({ permissions: ['execution.view'] }));
        await waitFor(() => expect(screen.queryByRole('link', { name: 'Connect site' })).not.toBeInTheDocument());
        noSiteManage.unmount();

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('fails closed for a missing endpoint, malformed response, or read failure', async () => {
        const missingFetch = vi.fn();
        vi.stubGlobal('fetch', missingFetch);
        const missing = renderControl(context({ api: { execution: '/api/tenants/alpha/executions' } }));
        await waitFor(() => expect(screen.queryByRole('link', { name: 'Connect site' })).not.toBeInTheDocument());
        expect(missingFetch).not.toHaveBeenCalled();
        missing.unmount();

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ data: [] })));
        const malformed = renderControl();
        await waitFor(() => expect(screen.queryByRole('link', { name: 'Connect site' })).not.toBeInTheDocument());
        malformed.unmount();

        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('network unavailable')));
        renderControl();
        await waitFor(() => expect(screen.queryByRole('link', { name: 'Connect site' })).not.toBeInTheDocument());
    });

    it('preserves the canonical Arabic copy and RTL locale behavior', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse([])));

        renderControl(context(), 'ar');

        const link = await screen.findByRole('link', { name: 'إضافة موقع' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites/connect');
        expect(screen.getByText('لا توجد مواقع في حسابك')).toBeInTheDocument();
        await waitFor(() => expect(document.documentElement.dir).toBe('rtl'));
    });
});
