import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';

const route: WorkspaceRoute = {
    key: 'sites', path: '/sites', group: 'overview', icon: '◉',
    label: { en: 'Sites', ar: 'المواقع' },
    description: { en: 'Sites', ar: 'المواقع' },
    apiKey: 'sites', permission: 'sites.view', controls: [], kind: 'resource',
};
const context: FrontendContext = {
    user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['tenant.view', 'sites.view'], connectors: [], capabilities: {},
    api: { sites: '/api/tenants/alpha/sites' }, actions: {},
};

function respond(page: number, size: number) {
    return new Response(JSON.stringify({
        data: [{ id: page * 100, name: 'Site page '+page, url: 'https://example.test',
            status: 'active', connection_status: 'connected' }],
        total: 30, current_page: page, last_page: Math.ceil(30 / size), per_page: size,
        meta: { connected_count: 30, review_count: 0 },
    }), { status: 200, headers: { 'content-type': 'application/json' } });
}
function mount() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider><ToastProvider>
                <WorkspacePage context={context} route={route} />
            </ToastProvider></LocaleProvider>
        </QueryClientProvider>,
    );
}
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    window.history.replaceState(null, '', '/');
});

describe('Sites URL-backed server pagination (#1608)', () => {
    it('opens a deep link, retains unrelated URL values and restores an earlier page on popstate', async () => {
        const original = '/tenants/alpha/sites?tab=all&page=2&per_page=10&connection_filter=connected';
        window.history.replaceState(null, '', original);
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const query = new URL(String(input), 'http://localhost').searchParams;
            return respond(Number(query.get('page')), Number(query.get('per_page')));
        });
        vi.stubGlobal('fetch', fetchMock);
        mount();

        expect(await screen.findByText('Site page 2')).toBeInTheDocument();
        expect(new URL(String(fetchMock.mock.calls[0][0]), 'http://localhost').searchParams.get('connection_filter')).toBe('connected');
        expect(String(fetchMock.mock.calls[0][0])).toContain('page=2');
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(await screen.findByText('Site page 3')).toBeInTheDocument();
        expect(window.location.search).toContain('page=3');
        expect(window.location.search).toContain('tab=all');
        expect(window.location.search).toContain('connection_filter=connected');

        // A browser history navigation emits popstate after updating location.
        window.history.replaceState(null, '', original);
        window.dispatchEvent(new PopStateEvent('popstate'));
        expect(await screen.findByText('Site page 2')).toBeInTheDocument();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));
    });

    it('restores a searched page and keeps the search query aligned with navigation and filters', async () => {
        const original = '/tenants/alpha/sites?tab=connections&page=2&per_page=10&search=alpha';
        window.history.replaceState(null, '', original);
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const query = new URL(String(input), 'http://localhost').searchParams;
            return respond(Number(query.get('page')), Number(query.get('per_page')));
        });
        vi.stubGlobal('fetch', fetchMock);
        mount();

        expect(await screen.findByText('Site page 2')).toBeInTheDocument();
        expect(screen.getByRole('textbox', { name: 'Search' })).toHaveValue('alpha');
        expect(new URL(String(fetchMock.mock.calls[0][0]), 'http://localhost').searchParams.get('search')).toBe('alpha');
        fireEvent.change(screen.getByRole('textbox', { name: 'Search' }), { target: { value: 'beta' } });
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));
        expect(await screen.findByText('Site page 1')).toBeInTheDocument();
        await waitFor(() => expect(window.location.search).toContain('search=beta'));
        expect(window.location.search).toContain('page=1');
        expect(window.location.search).toContain('tab=connections');
        await waitFor(() => expect(fetchMock.mock.calls.some(([input]) =>
            new URL(String(input), 'http://localhost').searchParams.get('search') === 'beta')).toBe(true));

        window.history.replaceState(null, '', original);
        window.dispatchEvent(new PopStateEvent('popstate'));
        expect(await screen.findByText('Site page 2')).toBeInTheDocument();
        expect(screen.getByRole('textbox', { name: 'Search' })).toHaveValue('alpha');
    });

    it('replaces an out-of-range page with the authoritative final page after records disappear', async () => {
        window.history.replaceState(null, '', '/tenants/alpha/sites?tab=all&search=alpha&page=9&per_page=10');
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const query = new URL(String(input), 'http://localhost').searchParams;
            const requested = Number(query.get('page'));
            return new Response(JSON.stringify({
                data: [{ id: 200, name: 'Last surviving site', url: 'https://example.test',
                    status: 'active', connection_status: 'connected' }],
                total: 11, current_page: Math.min(requested, 2), last_page: 2, per_page: 10,
                meta: { connected_count: 11, review_count: 0 },
            }), { status: 200, headers: { 'content-type': 'application/json' } });
        });
        vi.stubGlobal('fetch', fetchMock);
        mount();
        expect(await screen.findByText('Last surviving site')).toBeInTheDocument();
        await waitFor(() => expect(window.location.search).toContain('page=2'));
        expect(window.location.search).toContain('tab=all');
        expect(window.location.search).toContain('search=alpha');
        await waitFor(() => expect(fetchMock.mock.calls.some(([input]) =>
            new URL(String(input), 'http://localhost').searchParams.get('page') === '2')).toBe(true));
    });

    it('resets the URL page on filter/size change and constrains invalid deep links', async () => {
        window.history.replaceState(null, '', '/tenants/alpha/sites?tab=other&page=9999999&per_page=500&connection_filter=invalid');
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const query = new URL(String(input), 'http://localhost').searchParams;
            return respond(Number(query.get('page')), Number(query.get('per_page')));
        });
        vi.stubGlobal('fetch', fetchMock);
        mount();

        expect(await screen.findByText('Site page 1')).toBeInTheDocument();
        expect(String(fetchMock.mock.calls[0][0])).toContain('page=1');
        expect(String(fetchMock.mock.calls[0][0])).toContain('per_page=25');
        fireEvent.click(screen.getByRole('button', { name: 'Connected 30' }));
        await waitFor(() => expect(window.location.search).toContain('connection_filter=connected'));
        expect(window.location.search).toContain('tab=other');
        expect(window.location.search).toContain('page=1');
        fireEvent.change(await screen.findByRole('combobox', { name: 'Rows per page' }), { target: { value: '10' } });
        await waitFor(() => expect(window.location.search).toContain('per_page=10'));
        expect(window.location.search).toContain('page=1');
        expect(fetchMock.mock.calls.every(([input]) => new URL(String(input), 'http://localhost').pathname === '/api/tenants/alpha/sites')).toBe(true);
    });
});
