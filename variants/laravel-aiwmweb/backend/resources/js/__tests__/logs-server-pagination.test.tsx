import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';

const route: WorkspaceRoute = {
    key: 'logs', path: '/module/logs', group: 'system', icon: 'x',
    label: { en: 'Logs', ar: 'السجلات' },
    description: { en: 'Logs', ar: 'السجلات' },
    apiKey: 'logs', permission: 'diagnostics.view', controls: [], kind: 'resource',
};

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    window.localStorage.clear();
});

describe('Real tenant log paging (#1608)', () => {
    it('uses new server GET pages and resets to page one on size changes', async () => {
        const response = (page: number, size: number, id: number) => new Response(JSON.stringify({
            data: [{ id, level: 'info', message: 'Log '+id, occurred_at: '2026-11-08T07:00:00Z' }],
            current_page: page, last_page: Math.ceil(60 / size), per_page: size, total: 60,
            meta: {
                scope: 'tenant_operation_logs', pagination_mode: 'server',
                tenant_records: 60, matching_records: 60, returned_records: 1,
                limit: size, truncated: false, empty_reason: 'not_empty',
            },
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
            permissions: ['tenant.view', 'diagnostics.view', 'operations.manage'],
            connectors: [], capabilities: {},
            api: { logs: '/tenants/alpha/admin/logs' }, actions: {},
        };
        const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        render(
            <MemoryRouter initialEntries={['/tenants/alpha/module/logs']}>
                <QueryClientProvider client={client}>
                    <LocaleProvider><ToastProvider><WorkspacePage context={context} route={route} /></ToastProvider></LocaleProvider>
                </QueryClientProvider>
            </MemoryRouter>,
        );

        expect(await screen.findByText('Log 101')).toBeInTheDocument();
        await waitFor(() => expect(fetchMock.mock.calls[0][0])
            .toBe('/tenants/alpha/admin/logs?page=1&per_page=25'));
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(await screen.findByText('Log 202')).toBeInTheDocument();
        await waitFor(() => expect(fetchMock.mock.calls[1][0])
            .toBe('/tenants/alpha/admin/logs?page=2&per_page=25'));

        fireEvent.change(screen.getByRole('combobox', { name: 'Rows per page' }), { target: { value: '50' } });
        expect(await screen.findByText('Log 303')).toBeInTheDocument();
        await waitFor(() => expect(fetchMock.mock.calls[2][0])
            .toBe('/tenants/alpha/admin/logs?page=1&per_page=50'));
        expect(screen.getByRole('button', { name: 'First' })).toBeDisabled();
        expect(fetchMock.mock.calls.every(([, options]) => !options || !options.method || options.method === 'GET')).toBe(true);
    });
});
