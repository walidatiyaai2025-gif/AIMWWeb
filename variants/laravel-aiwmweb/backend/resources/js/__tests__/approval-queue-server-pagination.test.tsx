import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';

const route: WorkspaceRoute = {
    key: 'approvals', path: '/module/approvals', group: 'automation', icon: 'x',
    label: { en: 'Approvals', ar: 'الموافقات' },
    description: { en: 'Approvals', ar: 'الموافقات' },
    apiKey: 'approvals', permission: 'approvals.view', controls: [], kind: 'resource',
};

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    window.localStorage.clear();
});

describe('Approval Queue server-backed pagination (#1608)', () => {
    it('requests authoritative pages and resets to page one when size changes', async () => {
        const mockResponse = (page: number, size: number, title: string) =>
            new Response(JSON.stringify({
                data: [{ id: page, title, status: 'PENDING' }],
                current_page: page, last_page: Math.ceil(60 / size),
                per_page: size, total: 60,
            }), { status: 200, headers: { 'content-type': 'application/json' } });
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(mockResponse(1, 25, 'First approval'))
            .mockResolvedValueOnce(mockResponse(2, 25, 'Second approval'))
            .mockResolvedValueOnce(mockResponse(1, 50, 'Resized approval'));
        vi.stubGlobal('fetch', fetchMock);

        const context: FrontendContext = {
            user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
            tenant: { slug: 'alpha', name: 'Alpha' },
            tenants: [{ slug: 'alpha', name: 'Alpha' }],
            permissions: ['tenant.view', 'approvals.view'],
            connectors: [], capabilities: {},
            api: { approvals: '/api/tenants/alpha/approvals' },
            actions: {},
        };
        const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        render(
            <QueryClientProvider client={client}>
                <LocaleProvider><ToastProvider><WorkspacePage context={context} route={route} /></ToastProvider></LocaleProvider>
            </QueryClientProvider>,
        );

        expect(await screen.findByText('First approval')).toBeInTheDocument();
        await waitFor(() => expect(fetchMock.mock.calls[0][0]).toBe('/api/tenants/alpha/approvals?page=1&per_page=25'));
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(await screen.findByText('Second approval')).toBeInTheDocument();
        await waitFor(() => expect(fetchMock.mock.calls[1][0]).toBe('/api/tenants/alpha/approvals?page=2&per_page=25'));

        fireEvent.change(screen.getByRole('combobox', { name: 'Rows per page' }), { target: { value: '50' } });
        expect(await screen.findByText('Resized approval')).toBeInTheDocument();
        await waitFor(() => expect(fetchMock.mock.calls[2][0]).toBe('/api/tenants/alpha/approvals?page=1&per_page=50'));
        expect(screen.getByRole('button', { name: 'First' })).toBeDisabled();
        expect(fetchMock.mock.calls.every(([, options]) => !options || !options.method || options.method === 'GET')).toBe(true);
    });
});
