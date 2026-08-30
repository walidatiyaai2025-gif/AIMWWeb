import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';

const OPERATION_ID = 'AIMW-CONT-C178278FCB';

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

describe(`${OPERATION_ID} ShowAllSites`, () => {
    it('shows the complete authoritative Sites result locally without issuing another request or mutation', async () => {
        const fetchMock = vi.fn().mockResolvedValueOnce(new Response(JSON.stringify([
            { id: 11, name: 'Connected site', status: 'active' },
            { id: 12, name: 'Disabled site', status: 'disabled' },
        ]), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        expect(await screen.findByText('Connected site')).toBeInTheDocument();
        expect(screen.getByText('Disabled site')).toBeInTheDocument();

        const showAll = screen.getByRole('button', { name: 'All' });
        expect(showAll).toHaveAttribute('data-canonical-operation', OPERATION_ID);
        expect(showAll).toHaveAttribute('aria-pressed', 'true');
        expect(fetchMock).toHaveBeenCalledTimes(1);

        fireEvent.click(showAll);

        expect(screen.getByText('Connected site')).toBeInTheDocument();
        expect(screen.getByText('Disabled site')).toBeInTheDocument();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(fetchMock.mock.calls.every(([, options]) => !options || options.method === undefined || options.method === 'GET')).toBe(true);
    });
});
