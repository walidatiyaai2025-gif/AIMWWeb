import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';

const OPERATION_ID = 'AIMW-SYNC-B78216241F';

const route: WorkspaceRoute = {
    key: 'wp-users',
    path: '/module/users',
    group: 'content',
    icon: 'x',
    label: { en: 'WordPress Users', ar: 'مستخدمو WordPress' },
    description: { en: 'WordPress Users', ar: 'مستخدمو WordPress' },
    apiKey: 'wp-users',
    permission: 'sites.view',
    controls: [],
    kind: 'resource',
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

describe(OPERATION_ID, () => {
    it('binds Previous to the WordPress users collection and performs only the authoritative GET for the prior page', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ id: 1, name: 'Page one user' }],
                current_page: 1,
                last_page: 3,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ id: 2, name: 'Page two user' }],
                current_page: 2,
                last_page: 3,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ id: 1, name: 'Page one user' }],
                current_page: 1,
                last_page: 3,
            }), { status: 200, headers: { 'content-type': 'application/json' } }));

        vi.stubGlobal('fetch', fetchMock);
        renderUsers();

        expect(await screen.findByText('Page one user')).toBeInTheDocument();

        const previousOnFirstPage = screen.getByRole('button', { name: 'Previous' });
        expect(previousOnFirstPage).toHaveAttribute('data-canonical-operation', OPERATION_ID);
        expect(previousOnFirstPage).toBeDisabled();

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(await screen.findByText('Page two user')).toBeInTheDocument();

        const previous = screen.getByRole('button', { name: 'Previous' });
        expect(previous).toHaveAttribute('data-canonical-operation', OPERATION_ID);
        expect(previous).not.toBeDisabled();

        fireEvent.click(previous);
        expect(await screen.findByText('Page one user')).toBeInTheDocument();

        await waitFor(() => expect(fetchMock.mock.calls[2][0]).toBe('/api/v1/tenants/alpha/sites/7/users?page=1'));
        expect(fetchMock.mock.calls.every(([, options]) => !options || !options.method || options.method === 'GET')).toBe(true);
    });
});
