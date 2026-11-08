import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';

const route: WorkspaceRoute = {
    key: 'notifications', path: '/notifications', group: 'operations', icon: 'x',
    label: { en: 'Notifications', ar: 'الإشعارات' },
    description: { en: 'Inbox', ar: 'صندوق الإشعارات' },
    apiKey: 'notifications', permission: 'notifications.view', controls: [], kind: 'resource',
};

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    window.localStorage.clear();
});

describe('Notification Inbox server pagination (#1608)', () => {
    it('requests exact scoped pages and resets when page size changes', async () => {
        const response = (page: number, size: number, id: number) => new Response(JSON.stringify({
            data: [{ id, title: 'Notice '+id }],
            current_page: page, last_page: Math.ceil(60 / size),
            per_page: size, total: 60,
        }), { status: 200, headers: { 'content-type': 'application/json' } });
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(response(1, 25, 101))
            .mockResolvedValueOnce(response(2, 25, 202))
            .mockResolvedValueOnce(response(1, 50, 303));
        vi.stubGlobal('fetch', fetchMock);
        const context: FrontendContext = {
            user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
            tenant: { slug: 'alpha', name: 'Alpha' },
            tenants: [{ slug: 'alpha', name: 'Alpha' }],
            permissions: ['tenant.view', 'notifications.view'], connectors: [], capabilities: {},
            api: { notifications: '/api/tenants/alpha/notifications' }, actions: {},
        };
        const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        render(
            <QueryClientProvider client={client}>
                <LocaleProvider><ToastProvider><WorkspacePage context={context} route={route} /></ToastProvider></LocaleProvider>
            </QueryClientProvider>,
        );
        expect(await screen.findByRole('cell', { name: '101' })).toBeInTheDocument();
        await waitFor(() => expect(fetchMock.mock.calls[0][0]).toBe('/api/tenants/alpha/notifications?page=1&per_page=25'));
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(await screen.findByRole('cell', { name: '202' })).toBeInTheDocument();
        await waitFor(() => expect(fetchMock.mock.calls[1][0]).toBe('/api/tenants/alpha/notifications?page=2&per_page=25'));
        fireEvent.change(screen.getByRole('combobox', { name: 'Rows per page' }), { target: { value: '50' } });
        expect(await screen.findByRole('cell', { name: '303' })).toBeInTheDocument();
        await waitFor(() => expect(fetchMock.mock.calls[2][0]).toBe('/api/tenants/alpha/notifications?page=1&per_page=50'));
        expect(screen.getByRole('button', { name: 'First' })).toBeDisabled();
        expect(fetchMock.mock.calls.every(([, options]) => !options || !options.method || options.method === 'GET')).toBe(true);
    });
});
