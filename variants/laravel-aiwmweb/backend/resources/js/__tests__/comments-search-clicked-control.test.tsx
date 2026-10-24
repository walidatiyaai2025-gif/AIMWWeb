import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { COMMENTS_SEARCH_OPERATION_ID, WorkspacePage } from '../pages';

const route: WorkspaceRoute = {
    key: 'comments', path: '/module/comments', group: 'content', icon: 'x',
    label: { en: 'Comments', ar: 'التعليقات' }, description: { en: 'Comments', ar: 'التعليقات' },
    apiKey: 'comments', permission: 'content.view', controls: [], kind: 'resource',
};

function renderComments() {
    const context: FrontendContext = {
        user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'content.view'],
        connectors: [],
        capabilities: {},
        api: { comments: '/api/v1/tenants/alpha/sites/7/comments' },
        actions: {},
    };
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <ToastProvider>
                    <WorkspacePage context={context} route={route} />
                </ToastProvider>
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => vi.unstubAllGlobals());

describe('AIMW-SYNC-4E3C0156FC Comments SearchClickedAsync', () => {
    it('marks the real search control and reloads authoritative results from page one', async () => {
        expect(COMMENTS_SEARCH_OPERATION_ID).toBe('AIMW-SYNC-4E3C0156FC');
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ id: 1, author_name: 'First page', body: 'initial' }],
                current_page: 1, last_page: 3,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ id: 2, author_name: 'Second page', body: 'before search' }],
                current_page: 2, last_page: 3,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ id: 3, author_name: 'Needle Author', body: 'matched result' }],
                current_page: 1, last_page: 1,
            }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderComments();
        expect(await screen.findByRole('cell', { name: '1' })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(await screen.findByRole('cell', { name: '2' })).toBeInTheDocument();

        fireEvent.change(screen.getByRole('searchbox'), { target: { value: ' needle ' } });
        const search = screen.getByRole('button', { name: 'Search' });
        expect(search).toHaveAttribute('data-canonical-operation', COMMENTS_SEARCH_OPERATION_ID);
        fireEvent.click(search);

        expect(await screen.findByRole('cell', { name: '3' })).toBeInTheDocument();
        await waitFor(() => expect(fetchMock.mock.calls[2][0]).toBe('/api/v1/tenants/alpha/sites/7/comments?page=1&search=needle'));
        expect(fetchMock.mock.calls.every(([, options]) => !options || !options.method || options.method === 'GET')).toBe(true);
    });

    it('renders deterministic empty state for an authoritative empty search', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ data: [], current_page: 1, last_page: 1 }), {
                status: 200, headers: { 'content-type': 'application/json' },
            }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ data: [], current_page: 1, last_page: 1 }), {
                status: 200, headers: { 'content-type': 'application/json' },
            }));
        vi.stubGlobal('fetch', fetchMock);

        renderComments();
        await screen.findByRole('button', { name: 'Search' });
        fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'missing' } });
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));

        await waitFor(() => expect(fetchMock.mock.calls[1][0]).toBe('/api/v1/tenants/alpha/sites/7/comments?page=1&search=missing'));
        expect(await screen.findByText('No records were returned.')).toBeInTheDocument();
    });

    it('surfaces authoritative request failure instead of presenting fake success', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ id: 1, author_name: 'Loaded', body: 'initial' }],
                current_page: 1, last_page: 1,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ message: 'Search failed' }), {
                status: 500, headers: { 'content-type': 'application/json' },
            }));
        vi.stubGlobal('fetch', fetchMock);

        renderComments();
        expect(await screen.findByRole('cell', { name: '1' })).toBeInTheDocument();
        fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'boom' } });
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));

        expect(await screen.findByText('Data could not be loaded')).toBeInTheDocument();
        expect(screen.getByText('Search failed')).toBeInTheDocument();
        expect(screen.queryByText(/success/i)).toBeNull();
    });
});
