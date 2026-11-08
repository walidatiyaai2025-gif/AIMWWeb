import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';

const route: WorkspaceRoute = {
    key: 'evidence', path: '/module/evidence', group: 'seo', icon: 'x',
    label: { en: 'Evidence', ar: 'الأدلة' },
    description: { en: 'Evidence receipts', ar: 'إيصالات الأدلة' },
    apiKey: 'evidence', permission: 'execution.view', controls: [], kind: 'resource',
};

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    window.localStorage.clear();
});

describe('Evidence Receipt real server paging (#1608)', () => {
    it('loads bounded page sizes via safe tenant GETs and resets on resizing', async () => {
        const response = (page: number, size: number, title: string) =>
            new Response(JSON.stringify({
                data: [{ id: page, operation_id: title }],
                current_page: page, last_page: Math.ceil(60 / size),
                per_page: size, total: 60,
            }), { status: 200, headers: { 'content-type': 'application/json' } });
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(response(1, 25, 'Evidence receipt first'))
            .mockResolvedValueOnce(response(2, 25, 'Evidence receipt second'))
            .mockResolvedValueOnce(response(1, 50, 'Evidence receipt resized'));
        vi.stubGlobal('fetch', fetchMock);

        const context: FrontendContext = {
            user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
            tenant: { slug: 'alpha', name: 'Alpha' },
            tenants: [{ slug: 'alpha', name: 'Alpha' }],
            permissions: ['tenant.view', 'execution.view'], connectors: [], capabilities: {},
            api: { evidence: '/api/tenants/alpha/evidence' }, actions: {},
        };
        const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        render(
            <QueryClientProvider client={client}>
                <LocaleProvider><ToastProvider><WorkspacePage context={context} route={route} /></ToastProvider></LocaleProvider>
            </QueryClientProvider>,
        );

        expect(await screen.findByText('Evidence receipt first')).toBeInTheDocument();
        await waitFor(() => expect(fetchMock.mock.calls[0][0]).toBe('/api/tenants/alpha/evidence?page=1&per_page=25'));
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(await screen.findByText('Evidence receipt second')).toBeInTheDocument();
        await waitFor(() => expect(fetchMock.mock.calls[1][0]).toBe('/api/tenants/alpha/evidence?page=2&per_page=25'));

        fireEvent.change(screen.getByRole('combobox', { name: 'Rows per page' }), { target: { value: '50' } });
        expect(await screen.findByText('Evidence receipt resized')).toBeInTheDocument();
        await waitFor(() => expect(fetchMock.mock.calls[2][0]).toBe('/api/tenants/alpha/evidence?page=1&per_page=50'));
        expect(screen.getByRole('button', { name: 'First' })).toBeDisabled();
        expect(fetchMock.mock.calls.every(([, options]) => !options || !options.method || options.method === 'GET')).toBe(true);
    });
});
