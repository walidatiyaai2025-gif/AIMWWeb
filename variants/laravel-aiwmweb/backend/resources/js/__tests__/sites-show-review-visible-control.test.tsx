import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';

const OPERATION_ID = 'AIMW-CONT-CA17F05024';

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

describe(`${OPERATION_ID} ShowReviewSites`, () => {
    it('filters the authoritative Sites collection to non-connected, non-unknown connection states without another request', async () => {
        const fetchMock = vi.fn().mockResolvedValueOnce(new Response(JSON.stringify([
            { id: 11, name: 'Connected site', status: 'active', connection_status: 'connected' },
            { id: 12, name: 'Authentication failed site', status: 'active', connection_status: 'authentication_failed' },
            { id: 13, name: 'Degraded site', status: 'active', connection_status: 'degraded' },
            { id: 14, name: 'Unknown site', status: 'active', connection_status: 'unknown' },
        ]), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        expect(await screen.findByText('Connected site')).toBeInTheDocument();
        expect(screen.getByText('Authentication failed site')).toBeInTheDocument();
        expect(screen.getByText('Degraded site')).toBeInTheDocument();
        expect(screen.getByText('Unknown site')).toBeInTheDocument();

        const showReview = screen.getByRole('button', { name: 'Needs review 2' });
        expect(showReview).toHaveAttribute('data-canonical-operation', OPERATION_ID);
        expect(showReview).toHaveAttribute('aria-pressed', 'false');

        fireEvent.click(showReview);

        expect(showReview).toHaveAttribute('aria-pressed', 'true');
        expect(screen.queryByText('Connected site')).not.toBeInTheDocument();
        expect(screen.getByText('Authentication failed site')).toBeInTheDocument();
        expect(screen.getByText('Degraded site')).toBeInTheDocument();
        expect(screen.queryByText('Unknown site')).not.toBeInTheDocument();

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(fetchMock.mock.calls.every(([, options]) => !options || options.method === undefined || options.method === 'GET')).toBe(true);
    });

    it('renders the existing deterministic empty state when no authoritative site needs review', async () => {
        const fetchMock = vi.fn().mockResolvedValueOnce(new Response(JSON.stringify([
            { id: 21, name: 'Connected site', status: 'active', connection_status: 'connected' },
            { id: 22, name: 'Unknown site', status: 'active', connection_status: 'unknown' },
        ]), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        const showReview = await screen.findByRole('button', { name: 'Needs review 0' });
        fireEvent.click(showReview);

        expect(screen.getByText('No sample rows are synthesized when the server returns an empty result.')).toBeInTheDocument();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    });
});
