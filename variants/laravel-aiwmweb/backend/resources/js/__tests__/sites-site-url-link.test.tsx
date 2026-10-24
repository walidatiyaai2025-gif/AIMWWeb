import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { SITES_SITE_URL_OPERATION_ID, WorkspacePage } from '../pages';

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

describe('AIMW-CONT-44A3B8E551 Sites SiteUrl link parity', () => {
    it('renders the authoritative site_url as the external link target and visible text', async () => {
        const fetchMock = vi.fn().mockResolvedValueOnce(new Response(JSON.stringify([
            {
                id: 11,
                name: 'Alpha Site',
                site_url: 'https://alpha.example.test',
                connection_status: 'connected',
                last_connection_test_at_utc: '2026-10-24T10:00:00Z',
            },
        ]), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        const link = await screen.findByRole('link', { name: 'https://alpha.example.test' });
        expect(SITES_SITE_URL_OPERATION_ID).toBe('AIMW-CONT-44A3B8E551');
        expect(link).toHaveAttribute('href', 'https://alpha.example.test');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener');
        expect(link).toHaveAttribute('data-canonical-operation', SITES_SITE_URL_OPERATION_ID);
        expect(screen.getByText('Alpha Site')).toBeInTheDocument();

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(fetchMock.mock.calls.every(([, options]) => !options || options.method === undefined || options.method === 'GET')).toBe(true);
    });

    it('does not synthesize a link when the authoritative row has an empty site_url', async () => {
        const fetchMock = vi.fn().mockResolvedValueOnce(new Response(JSON.stringify([
            {
                id: 12,
                name: 'No URL Site',
                site_url: '',
                connection_status: 'unknown',
                last_connection_test_at_utc: null,
            },
        ]), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        expect(await screen.findByText('No URL Site')).toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    });
});
