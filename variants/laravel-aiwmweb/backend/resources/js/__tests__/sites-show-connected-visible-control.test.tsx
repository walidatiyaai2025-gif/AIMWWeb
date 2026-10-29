import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';

const OPERATION_ID = 'AIMW-CONT-E346398EAD';

function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view'],
        connectors: [],
        capabilities: {},
        api: { sites: '/api/tenants/alpha/sites' },
        actions: {},
    };
}

const route: WorkspaceRoute = {
    key: 'sites',
    path: '/sites',
    group: 'overview',
    icon: '◉',
    label: { en: 'Sites', ar: 'المواقع' },
    description: { en: 'Manage connected WordPress sites.', ar: 'إدارة مواقع WordPress المتصلة.' },
    apiKey: 'sites',
    permission: 'sites.view',
    controls: [],
    kind: 'resource',
};

function renderWorkspace() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <ToastProvider>
                    <WorkspacePage context={context()} route={route} />
                </ToastProvider>
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe(`${OPERATION_ID} ShowConnectedSites`, () => {
    it('filters by authoritative connection_status instead of the unrelated status field', async () => {
        const fetchMock = vi.fn().mockResolvedValueOnce(new Response(JSON.stringify([
            { id: 11, name: 'Connected but inactive', status: 'inactive', connection_status: 'connected' },
            { id: 12, name: 'Active but failed', status: 'active', connection_status: 'authentication_failed' },
            { id: 13, name: 'Active unknown', status: 'active', connection_status: 'unknown' },
        ]), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        expect(await screen.findByText('Connected but inactive')).toBeInTheDocument();
        expect(screen.getByText('Active but failed')).toBeInTheDocument();
        expect(screen.getByText('Active unknown')).toBeInTheDocument();

        const connected = screen.getByRole('button', { name: 'Connected 1' });
        expect(connected).toHaveAttribute('data-canonical-operation', OPERATION_ID);
        expect(connected).toHaveAttribute('aria-pressed', 'false');

        fireEvent.click(connected);

        expect(connected).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByText('Connected but inactive')).toBeInTheDocument();
        expect(screen.queryByText('Active but failed')).not.toBeInTheDocument();
        expect(screen.queryByText('Active unknown')).not.toBeInTheDocument();

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(fetchMock.mock.calls.every(([, options]) => !options || options.method === undefined || options.method === 'GET')).toBe(true);
    });

    it('renders the deterministic empty state when no authoritative site is connected', async () => {
        const fetchMock = vi.fn().mockResolvedValueOnce(new Response(JSON.stringify([
            { id: 21, name: 'Failed site', status: 'active', connection_status: 'authentication_failed' },
            { id: 22, name: 'Unknown site', status: 'active', connection_status: 'unknown' },
        ]), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        const connected = await screen.findByRole('button', { name: 'Connected 0' });
        fireEvent.click(connected);

        expect(screen.getByText('No sample rows are synthesized when the server returns an empty result.')).toBeInTheDocument();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    });
});
