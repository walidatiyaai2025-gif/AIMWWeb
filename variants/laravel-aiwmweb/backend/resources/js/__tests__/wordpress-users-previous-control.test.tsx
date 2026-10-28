import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { WORDPRESS_USERS_PREVIOUS_OPERATION_ID, WorkspacePage } from '../pages';

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

describe('AIMW-SYNC-E0791EA9E9 GlobalUsersWorkspace PreviousAsync', () => {
    it('returns to the prior page through an authoritative same-tenant WordPress GET', async () => {
        expect(WORDPRESS_USERS_PREVIOUS_OPERATION_ID).toBe('AIMW-SYNC-E0791EA9E9');
        const response = (id: number, page: number) => new Response(JSON.stringify({
            data: [{ id, username: 'user' + id, name: 'User ' + id, email: 'u' + id + '@example.test' }],
            current_page: page, last_page: 3, total: 120,
        }), { status: 200, headers: { 'content-type': 'application/json' } });
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(response(11, 1))
            .mockResolvedValueOnce(response(22, 2))
            .mockResolvedValueOnce(response(33, 1));
        vi.stubGlobal('fetch', fetchMock);

        renderUsers();
        expect(await screen.findByRole('cell', { name: '11' })).toBeInTheDocument();
        const previous = screen.getByRole('button', { name: 'Previous' });
        expect(previous).toHaveAttribute('data-canonical-operation', WORDPRESS_USERS_PREVIOUS_OPERATION_ID);
        expect(previous).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(await screen.findByRole('cell', { name: '22' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Previous' })).not.toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Previous' }));
        expect(await screen.findByRole('cell', { name: '33' })).toBeInTheDocument();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));
        expect(fetchMock.mock.calls[2][0]).toBe('/api/v1/tenants/alpha/sites/7/users?page=1');
        expect(fetchMock.mock.calls.every(([, options]) => !options || !options.method || options.method === 'GET')).toBe(true);
    });

    it('does not substitute success when the WordPress previous-page read is denied', async () => {
        const response = (id: number, page: number) => new Response(JSON.stringify({
            data: [{ id, username: 'user' + id, name: 'User ' + id }],
            current_page: page, last_page: 3, total: 120,
        }), { status: 200, headers: { 'content-type': 'application/json' } });
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(response(11, 1))
            .mockResolvedValueOnce(response(22, 2))
            .mockResolvedValueOnce(new Response(JSON.stringify({ message: 'WordPress permission denied' }), {
                status: 403, headers: { 'content-type': 'application/json' },
            }));
        vi.stubGlobal('fetch', fetchMock);
        renderUsers();
        expect(await screen.findByRole('cell', { name: '11' })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(await screen.findByRole('cell', { name: '22' })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Previous' }));
        expect(await screen.findByText('Data could not be loaded')).toBeInTheDocument();
        expect(screen.getByText('WordPress permission denied')).toBeInTheDocument();
        expect(screen.queryByText(/success/i)).toBeNull();
    });
});
