import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ContentHubLiveContentPanel } from '../content-hub-live-content-panel';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (permissions = ['tenant.view', 'sites.view', 'content.view', 'content.edit']): FrontendContext => ({
    user: { id: 1, name: 'Owner', email: 'owner@example.test' },
    tenant: { slug: 'wordpress', name: 'WordPress' },
    tenants: [{ slug: 'wordpress', name: 'WordPress' }],
    permissions,
    connectors: [{ key: 'nob', state: 'connected', scopes: ['content.read'] }],
    capabilities: {},
    api: { sites: '/api/tenants/wordpress/sites' },
    actions: {},
});

function renderControl(value = context()) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <MemoryRouter initialEntries={['/tenants/wordpress/content']}>
                <LocaleProvider>
                    <ContentHubLiveContentPanel context={value} />
                </LocaleProvider>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

const json = (data: unknown, status = 200) => new Response(JSON.stringify(data), {
    status, headers: { 'content-type': 'application/json' },
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('real content hub read model', () => {
    it('loads real site posts/pages and lets the owner run canonical full sync', async () => {
        const requests: string[] = [];
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);
            requests.push(url);
            if (url === '/api/tenants/wordpress/sites') return json({ data: [{ id: 12, name: 'NOB' }] });
            if (url.includes('/content/post?')) return json({ data: [{ id: 5, remote_id: 37, title: 'Real published NOB post', type: 'post' }], total: 1 });
            if (url.includes('/content/page?')) return json({ data: [{ id: 8, remote_id: 21, title: 'Real NOB page', type: 'page' }], total: 1 });
            if (url.endsWith('/sync') && init?.method === 'POST') return json({ id: 44, state: 'completed' }, 202);
            throw new Error('Unexpected request: ' + url);
        });
        vi.stubGlobal('fetch', fetchMock);

        renderControl();
        expect(await screen.findByText('Real published NOB post')).toBeInTheDocument();
        expect(screen.getByText('Real NOB page')).toBeInTheDocument();
        expect(screen.getByText(/Posts: 1/)).toBeInTheDocument();
        expect(screen.getByText(/Pages: 1/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Sync WordPress content now' }));
        await screen.findByText(/Content sync completed/);
        const posted = fetchMock.mock.calls.find(([url, init]) => String(url).endsWith('/sync') && init?.method === 'POST');
        expect(posted).toBeDefined();
        expect(JSON.parse(String(posted?.[1]?.body))).toEqual({
            full: true, resources: ['posts', 'pages', 'media', 'categories', 'tags', 'comments'],
        });
        expect(requests.some((url) => url.includes('/sites/12/content/post'))).toBe(true);
        expect(screen.getByRole('link', { name: 'All posts' })).toHaveAttribute('href', '/tenants/wordpress/module/posts?site=12');
    });

    it('shows the actual empty read model rather than claiming the Connector badge equals data', async () => {
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);
            if (url.endsWith('/sites')) return json([{ id: 7, name: 'NOB' }]);
            if (url.includes('/content/')) return json({ data: [], total: 0 });
            throw new Error('Unexpected URL: ' + url);
        }));
        renderControl(context(['tenant.view', 'sites.view', 'content.view']));
        expect(await screen.findByText(/No synchronized posts or pages exist/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Sync WordPress content now' })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Sync details' })).toHaveAttribute('href', '/tenants/wordpress/module/sync?site=7');
    });

    it('fails closed for missing permission and foreign site contracts', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        renderControl(context(['tenant.view', 'content.view']));
        await waitFor(() => expect(fetchMock).not.toHaveBeenCalled());
        expect(screen.queryByLabelText('Real WordPress content')).not.toBeInTheDocument();
    });
});
