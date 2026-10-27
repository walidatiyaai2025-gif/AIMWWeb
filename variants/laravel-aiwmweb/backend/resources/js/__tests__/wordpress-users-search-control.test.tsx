import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { WORDPRESS_USERS_SEARCH_OPERATION_ID, WorkspacePage } from '../pages';

const route: WorkspaceRoute = {
    key: 'wp-users', path: '/module/users', group: 'content', icon: 'x',
    label: { en: 'WordPress Users', ar: 'مستخدمو WordPress' },
    description: { en: 'WordPress Users', ar: 'مستخدمو WordPress' },
    apiKey: 'wp-users', permission: 'sites.view', controls: [], kind: 'resource',
};

function renderUsers() {
    const context: FrontendContext = {
        user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view'],
        connectors: [],
        capabilities: {},
        api: { 'wp-users': '/api/v1/tenants/alpha/sites/7/users' },
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

describe('AIMW-SYNC-E6888B0B4D GlobalUsersWorkspace SearchAsync', () => {
    it('binds the canonical search control, resets to page one, and reloads authoritative users', async () => {
        expect(WORDPRESS_USERS_SEARCH_OPERATION_ID).toBe('AIMW-SYNC-E6888B0B4D');
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ id: 11, username: 'first', name: 'First User', email: 'first@example.test' }],
                current_page: 1, last_page: 3, total: 1,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ id: 22, username: 'second', name: 'Second User', email: 'second@example.test' }],
                current_page: 2, last_page: 3, total: 1,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ id: 33, username: 'needle', name: 'Needle User', email: 'needle@example.test' }],
                current_page: 1, last_page: 1, total: 1,
            }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderUsers();
        expect(await screen.findByRole('cell', { name: '11' })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(await screen.findByRole('cell', { name: '22' })).toBeInTheDocument();

        fireEvent.change(screen.getByRole('textbox', { name: 'Search' }), { target: { value: ' needle ' } });
        const search = screen.getByRole('button', { name: 'Search' });
        expect(search).toHaveAttribute('data-canonical-operation', WORDPRESS_USERS_SEARCH_OPERATION_ID);
        fireEvent.click(search);

        expect(await screen.findByRole('cell', { name: '33' })).toBeInTheDocument();
        await waitFor(() => expect(fetchMock.mock.calls[2][0]).toBe('/api/v1/tenants/alpha/sites/7/users?page=1&search=needle'));
        expect(fetchMock.mock.calls.every(([, options]) => !options || !options.method || options.method === 'GET')).toBe(true);
    });

    it('surfaces an authoritative search failure instead of presenting fake success', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ id: 11, username: 'loaded', name: 'Loaded User', email: 'loaded@example.test' }],
                current_page: 1, last_page: 1, total: 1,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ message: 'WordPress users search failed' }), {
                status: 502, headers: { 'content-type': 'application/json' },
            }));
        vi.stubGlobal('fetch', fetchMock);

        renderUsers();
        expect(await screen.findByRole('cell', { name: '11' })).toBeInTheDocument();
        fireEvent.change(screen.getByRole('textbox', { name: 'Search' }), { target: { value: 'boom' } });
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));

        expect(await screen.findByText('Data could not be loaded')).toBeInTheDocument();
        expect(screen.getByText('WordPress users search failed')).toBeInTheDocument();
        expect(screen.queryByText(/success/i)).toBeNull();
    });
});
