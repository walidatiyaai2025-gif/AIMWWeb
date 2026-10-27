import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    APP_DATA_GRID_TOGGLE_SORT_OPERATION_ID,
    AppDataGridSortControl,
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

function pagePayload(page: number, descending: boolean) {
    const prefix = page === 1 ? '2026-10-26T' : '2026-10-25T';
    const newer = { delivery_id: `p${page}-newer`, status: 'SENT', created_at: `${prefix}10:00:00Z` };
    const older = { delivery_id: `p${page}-older`, status: 'FAILED', created_at: `${prefix}08:00:00Z` };

    return {
        data: descending ? [newer, older] : [older, newer],
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
    it('matches the source descending-flag toggle semantics exactly', () => {
        expect(toggleAppDataGridSortDescending(false)).toBe(true);
        expect(toggleAppDataGridSortDescending(true)).toBe(false);
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

    it('resets Email History to page 1 and requests server-side descending sort before pagination', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = new URL(String(input), 'https://app.example.test');
            const page = Number(url.searchParams.get('page') ?? '1');
            const descending = url.searchParams.get('sort_desc') === '1';

            return new Response(JSON.stringify(pagePayload(page, descending)), {
                status: 200,
                headers: { 'content-type': 'application/json' },
            });
        });
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        await screen.findByText('Page 1 of 2');
        await waitFor(() => expect(fetchMock.mock.calls.some(([input]) => {
            const url = String(input);
            return url.includes('page=1') && url.includes('sort_desc=0');
        })).toBe(true));

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        await screen.findByText('Page 2 of 2');

        fireEvent.click(screen.getByRole('button', { name: 'Sort: Sorted ascending' }));

        await screen.findByText('Page 1 of 2');
        await waitFor(() => expect(fetchMock.mock.calls.some(([input]) => {
            const url = String(input);
            return url.includes('page=1') && url.includes('sort_desc=1');
        })).toBe(true));

        const updatedSort = screen.getByRole('button', { name: 'Sort: Sorted descending' });
        expect(updatedSort).toHaveAttribute('aria-pressed', 'true');

        const rows = within(screen.getByRole('table')).getAllByRole('row').slice(1);
        expect(rows[0]).toHaveTextContent('2026-10-26T10:00:00Z');
        expect(rows[1]).toHaveTextContent('2026-10-26T08:00:00Z');
        expect(fetchMock.mock.calls.every(([, init]) => !init?.method || init.method === 'GET')).toBe(true);
    });
});
