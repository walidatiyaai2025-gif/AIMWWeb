import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    APP_DATA_GRID_PREVIOUS_PAGE_OPERATION_ID,
    previousAppDataGridPage,
} from '../app-data-grid-previous-page-control';
import { Pagination, ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';

const route: WorkspaceRoute = {
    key: 'email-history',
    path: '/email/history',
    group: 'operations',
    icon: '✉',
    label: { en: 'Email Delivery History', ar: 'سجل إرسال البريد' },
    description: { en: 'Inspect application email delivery history.', ar: 'مراجعة سجل إرسال البريد من التطبيق.' },
    apiKey: 'email-history',
    permission: 'diagnostics.view',
    kind: 'resource',
};

function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Operator', email: 'operator@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'tenant.manage', 'diagnostics.view'],
        connectors: [],
        capabilities: {},
        api: { 'email-history': '/api/v1/tenants/alpha/email/deliveries' },
        actions: {},
    };
}

function renderWorkspace() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: 0 } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <ToastProvider>
                    <WorkspacePage context={context()} route={route} />
                </ToastProvider>
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe(`${APP_DATA_GRID_PREVIOUS_PAGE_OPERATION_ID} AppDataGrid PreviousPageAsync`, () => {
    it('matches the source boundary semantics exactly', () => {
        expect(previousAppDataGridPage(3)).toBe(2);
        expect(previousAppDataGridPage(2)).toBe(1);
        expect(previousAppDataGridPage(1)).toBe(1);
    });

    it('keeps first-page previous disabled and does not dispatch', () => {
        const onPage = vi.fn();
        render(
            <LocaleProvider>
                <Pagination
                    page={1}
                    lastPage={2}
                    onPage={onPage}
                    previousOperationId={APP_DATA_GRID_PREVIOUS_PAGE_OPERATION_ID}
                />
            </LocaleProvider>,
        );

        const previous = screen.getByRole('button', { name: 'Previous' });
        expect(previous).toBeDisabled();
        expect(previous).toHaveAttribute('data-canonical-operation', APP_DATA_GRID_PREVIOUS_PAGE_OPERATION_ID);
        fireEvent.click(previous);
        expect(onPage).not.toHaveBeenCalled();
    });

    it('moves Email History from page 2 to page 1 and performs a fresh tenant-scoped read', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ delivery_id: 'page-1', status: 'SENT' }],
                total: 2,
                current_page: 1,
                last_page: 2,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ delivery_id: 'page-2', status: 'FAILED' }],
                total: 2,
                current_page: 2,
                last_page: 2,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ delivery_id: 'page-1', status: 'SENT' }],
                total: 2,
                current_page: 1,
                last_page: 2,
            }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        const next = await screen.findByRole('button', { name: 'Next' });
        fireEvent.click(next);
        await waitFor(() => expect(fetchMock.mock.calls.some(
            (call) => String(call[0]).includes('/api/v1/tenants/alpha/email/deliveries?page=2'),
        )).toBe(true));

        await screen.findByText('Page 2 of 2');
        const previous = await screen.findByRole('button', { name: 'Previous' });
        expect(previous).toBeEnabled();
        expect(previous).toHaveAttribute('data-canonical-operation', APP_DATA_GRID_PREVIOUS_PAGE_OPERATION_ID);
        fireEvent.click(previous);

        await waitFor(() => {
            const pageOneReads = fetchMock.mock.calls.filter(
                (call) => String(call[0]).includes('/api/v1/tenants/alpha/email/deliveries?page=1'),
            );
            expect(pageOneReads.length).toBeGreaterThanOrEqual(2);
        });
        expect(await screen.findByText('Page 1 of 2')).toBeInTheDocument();
    });
});
