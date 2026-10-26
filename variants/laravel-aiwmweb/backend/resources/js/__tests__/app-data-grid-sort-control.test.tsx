import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    APP_DATA_GRID_TOGGLE_SORT_OPERATION_ID,
    AppDataGridSortControl,
    sortAppDataGridRows,
    toggleAppDataGridSortDescending,
} from '../app-data-grid-sort-control';
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

function pagePayload(page: number) {
    const prefix = page === 1 ? '2026-10-26T' : '2026-10-25T';
    return {
        data: [
            { delivery_id: `p${page}-newer`, status: 'SENT', created_at: `${prefix}10:00:00Z` },
            { delivery_id: `p${page}-older`, status: 'FAILED', created_at: `${prefix}08:00:00Z` },
        ],
        total: 4,
        current_page: page,
        last_page: 2,
    };
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe(`${APP_DATA_GRID_TOGGLE_SORT_OPERATION_ID} AppDataGrid ToggleSortAsync`, () => {
    it('matches the source toggle and case-insensitive key ordering semantics', () => {
        expect(toggleAppDataGridSortDescending(false)).toBe(true);
        expect(toggleAppDataGridSortDescending(true)).toBe(false);

        const rows = [{ key: 'Beta' }, { key: 'alpha' }, { key: 'charlie' }];
        expect(sortAppDataGridRows(rows, (row) => row.key, false).map((row) => row.key))
            .toEqual(['alpha', 'Beta', 'charlie']);
        expect(sortAppDataGridRows(rows, (row) => row.key, true).map((row) => row.key))
            .toEqual(['charlie', 'Beta', 'alpha']);
        expect(rows.map((row) => row.key)).toEqual(['Beta', 'alpha', 'charlie']);
    });

    it('renders the exact canonical marker and toggles the pressed sort direction', () => {
        const onToggle = vi.fn();
        render(
            <AppDataGridSortControl
                sortDescending={false}
                onToggle={onToggle}
            />,
        );

        const sort = screen.getByRole('button', { name: 'Sort: Sorted ascending' });
        expect(sort).toHaveAttribute('data-canonical-operation', APP_DATA_GRID_TOGGLE_SORT_OPERATION_ID);
        expect(sort).toHaveAttribute('aria-pressed', 'false');
        fireEvent.click(sort);
        expect(onToggle).toHaveBeenCalledWith(true);
    });

    it('resets Email History to page 1, sorts authoritative rows, and performs no mutation request', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);
            const page = url.includes('page=2') ? 2 : 1;
            return new Response(JSON.stringify(pagePayload(page)), {
                status: 200,
                headers: { 'content-type': 'application/json' },
            });
        });
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        await screen.findByText('Page 1 of 2');
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        await screen.findByText('Page 2 of 2');

        const sort = screen.getByRole('button', { name: 'Sort: Sorted ascending' });
        fireEvent.click(sort);

        await screen.findByText('Page 1 of 2');
        await waitFor(() => {
            const pageOneReads = fetchMock.mock.calls.filter(([input]) => String(input).includes('page=1'));
            expect(pageOneReads.length).toBeGreaterThanOrEqual(2);
        });

        const updatedSort = screen.getByRole('button', { name: 'Sort: Sorted descending' });
        expect(updatedSort).toHaveAttribute('aria-pressed', 'true');

        const rows = within(screen.getByRole('table')).getAllByRole('row').slice(1);
        expect(rows[0]).toHaveTextContent('2026-10-26T10:00:00Z');
        expect(rows[1]).toHaveTextContent('2026-10-26T08:00:00Z');
        expect(fetchMock.mock.calls.every(([, init]) => !init?.method || init.method === 'GET')).toBe(true);
    });
});
