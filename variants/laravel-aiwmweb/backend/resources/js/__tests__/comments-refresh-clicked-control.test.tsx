import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { COMMENTS_REFRESH_CLICK_OPERATION_ID, WorkspacePage } from '../pages';

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

describe('AIMW-SYNC-D5CE5A213B Comments RefreshClickedAsync', () => {
    it('binds the canonical refresh control to an authoritative GET reread', async () => {
        expect(COMMENTS_REFRESH_CLICK_OPERATION_ID).toBe('AIMW-SYNC-D5CE5A213B');
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ id: 1, author_name: 'Before', body: 'stale' }],
                current_page: 1, last_page: 1,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ id: 2, author_name: 'After', body: 'authoritative' }],
                current_page: 1, last_page: 1,
            }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderComments();
        expect(await screen.findByRole('cell', { name: '1' })).toBeInTheDocument();

        const refresh = screen.getByRole('button', { name: 'Refresh' });
        expect(refresh).toHaveAttribute('data-canonical-operation', COMMENTS_REFRESH_CLICK_OPERATION_ID);
        fireEvent.click(refresh);

        expect(await screen.findByRole('cell', { name: '2' })).toBeInTheDocument();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(fetchMock.mock.calls[1][0]).toBe('/api/v1/tenants/alpha/sites/7/comments?page=1');
        expect(fetchMock.mock.calls.every(([, options]) => !options || !options.method || options.method === 'GET')).toBe(true);
    });

    it('surfaces refresh failure without fake success', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ id: 1, author_name: 'Loaded', body: 'initial' }],
                current_page: 1, last_page: 1,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ message: 'Refresh failed' }), {
                status: 500, headers: { 'content-type': 'application/json' },
            }));
        vi.stubGlobal('fetch', fetchMock);

        renderComments();
        await screen.findByRole('cell', { name: '1' });
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));

        expect(await screen.findByText('Data could not be loaded')).toBeInTheDocument();
        expect(screen.getByText('Refresh failed')).toBeInTheDocument();
        expect(screen.queryByText(/success/i)).toBeNull();
    });
});
