import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    APP_DATA_GRID_CLEAR_FILTERS_OPERATION_ID,
    AppDataGridClearFiltersControl,
    clearAppDataGridFilters,
} from '../app-data-grid-clear-filters-control';
import { ToastProvider } from '../components';
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

describe(`${APP_DATA_GRID_CLEAR_FILTERS_OPERATION_ID} AppDataGrid ClearFiltersAsync`, () => {
    it('mirrors source ordering: clear search, reset page, raise SearchChanged, then ClearFiltersRequested', async () => {
        const calls: string[] = [];

        await clearAppDataGridFilters({
            onSearchInputChange: (value) => calls.push(`input:${value}`),
            onPageChange: (page) => calls.push(`page:${page}`),
            onSearchChanged: (value) => { calls.push(`search:${value}`); },
            onClearFiltersRequested: () => { calls.push('filters'); },
        });

        expect(calls).toEqual(['input:', 'page:1', 'search:', 'filters']);
    });

    it('renders only for effective active filters and exposes the exact canonical operation', () => {
        const callbacks = {
            onSearchInputChange: vi.fn(),
            onPageChange: vi.fn(),
            onSearchChanged: vi.fn(),
            onClearFiltersRequested: vi.fn(),
        };
        const { rerender } = render(
            <AppDataGridClearFiltersControl
                locale="en"
                searchValue=""
                activeFilterCount={0}
                {...callbacks}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Clear filters' })).not.toBeInTheDocument();

        rerender(
            <AppDataGridClearFiltersControl
                locale="en"
                searchValue="needle"
                activeFilterCount={0}
                {...callbacks}
            />,
        );

        expect(screen.getByRole('button', { name: 'Clear filters' }))
            .toHaveAttribute('data-canonical-operation', APP_DATA_GRID_CLEAR_FILTERS_OPERATION_ID);
    });

    it('clears applied Email History filters and performs a fresh unfiltered tenant read', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ delivery_id: 'before', correlation_id: 'old', status: 'QUEUED', template: 'before' }],
                total: 1, current_page: 1, last_page: 1,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ delivery_id: 'filtered', correlation_id: 'trace-1', status: 'SENT', template: 'filtered' }],
                total: 1, current_page: 1, last_page: 1,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [
                    { delivery_id: 'before', correlation_id: 'old', status: 'QUEUED', template: 'before' },
                    { delivery_id: 'filtered', correlation_id: 'trace-1', status: 'SENT', template: 'filtered' },
                ],
                total: 2, current_page: 1, last_page: 1,
            }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();
        expect(await screen.findByRole('cell', { name: 'QUEUED' })).toBeInTheDocument();

        fireEvent.change(screen.getByPlaceholderText('Site ID'), { target: { value: '7' } });
        fireEvent.change(screen.getByRole('combobox', { name: 'Status' }), { target: { value: 'SENT' } });
        fireEvent.change(screen.getByPlaceholderText('Correlation ID'), { target: { value: ' trace-1 ' } });
        fireEvent.click(screen.getByRole('button', { name: 'Apply' }));

        expect(await screen.findByRole('cell', { name: 'SENT' })).toBeInTheDocument();
        const clear = await screen.findByRole('button', { name: 'Clear filters' });
        expect(clear).toHaveAttribute('data-canonical-operation', APP_DATA_GRID_CLEAR_FILTERS_OPERATION_ID);
        fireEvent.click(clear);

        await waitFor(() => expect(fetchMock.mock.calls.length).toBeGreaterThanOrEqual(3));
        const unfilteredCalls = fetchMock.mock.calls
            .map((call) => String(call[0]))
            .filter((url) => url.includes('/api/v1/tenants/alpha/email/deliveries?page=1')
                && !url.includes('site_id=')
                && !url.includes('status=')
                && !url.includes('correlation=')
                && !url.includes('search='));
        expect(unfilteredCalls.length).toBeGreaterThanOrEqual(2);
        expect(screen.getByPlaceholderText('Site ID')).toHaveValue(null);
        expect(screen.getByRole('combobox', { name: 'Status' })).toHaveValue('');
        expect(screen.getByPlaceholderText('Correlation ID')).toHaveValue('');
    });

    it('does not dispatch while the authoritative workspace is busy', () => {
        const onClearFiltersRequested = vi.fn();
        render(
            <AppDataGridClearFiltersControl
                locale="en"
                searchValue="needle"
                activeFilterCount={1}
                busy
                onSearchInputChange={vi.fn()}
                onPageChange={vi.fn()}
                onSearchChanged={vi.fn()}
                onClearFiltersRequested={onClearFiltersRequested}
            />,
        );

        const button = screen.getByRole('button', { name: 'Clear filters' });
        expect(button).toBeDisabled();
        fireEvent.click(button);
        expect(onClearFiltersRequested).not.toHaveBeenCalled();
    });
});
