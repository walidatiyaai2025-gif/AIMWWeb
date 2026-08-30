import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';

const COMMENT_LOAD = 'AIMW-SYNC-12F15A0A80';
const COMMENT_PREVIOUS = 'AIMW-SYNC-CB01197D47';
const COMMENT_REFRESH = 'AIMW-SYNC-DBD736FACC';

const route: WorkspaceRoute = {
    key: 'comments', path: '/module/comments', group: 'content', icon: 'x',
    label: { en: 'Comments', ar: 'التعليقات' }, description: { en: 'Comments', ar: 'التعليقات' },
    apiKey: 'comments', permission: 'content.view', controls: [], kind: 'resource',
};

function renderComments() {
    const context: FrontendContext = {
        user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' }, tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'content.view'], connectors: [], capabilities: {},
        api: { comments: '/api/tenants/alpha/comments' }, actions: {},
    };
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(<QueryClientProvider client={client}><LocaleProvider><ToastProvider><WorkspacePage context={context} route={route} /></ToastProvider></LocaleProvider></QueryClientProvider>);
}

afterEach(() => vi.unstubAllGlobals());

describe(`${COMMENT_LOAD} ${COMMENT_PREVIOUS} ${COMMENT_REFRESH}`, () => {
    it('marks refresh/load and previous on their real tenant collection controls', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ data: [{ id: 1, name: 'Page one' }], current_page: 1, last_page: 3 }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ data: [{ id: 2, name: 'Page two' }], current_page: 2, last_page: 3 }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ data: [{ id: 1, name: 'Page one' }], current_page: 1, last_page: 3 }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ data: [{ id: 3, name: 'Refreshed' }], current_page: 1, last_page: 3 }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);
        renderComments();

        expect(await screen.findByText('Page one')).toBeInTheDocument();
        const refresh = screen.getByRole('button', { name: 'Refresh' });
        expect(refresh).toHaveAttribute('data-canonical-load-operation', COMMENT_LOAD);
        expect(refresh).toHaveAttribute('data-canonical-refresh-operation', COMMENT_REFRESH);
        const previous = screen.getByRole('button', { name: 'Previous' });
        expect(previous).toHaveAttribute('data-canonical-operation', COMMENT_PREVIOUS);

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(await screen.findByText('Page two')).toBeInTheDocument();
        const pageTwoPrevious = screen.getByRole('button', { name: 'Previous' });
        expect(pageTwoPrevious).toHaveAttribute('data-canonical-operation', COMMENT_PREVIOUS);
        fireEvent.click(pageTwoPrevious);
        expect(await screen.findByText('Page one')).toBeInTheDocument();
        await waitFor(() => expect(fetchMock.mock.calls[2][0]).toBe('/api/tenants/alpha/comments?page=1'));
        fireEvent.click(refresh);
        expect(await screen.findByText('Refreshed')).toBeInTheDocument();
        expect(fetchMock.mock.calls.every(([, options]) => !options || !options.method || options.method === 'GET')).toBe(true);
    });
});
