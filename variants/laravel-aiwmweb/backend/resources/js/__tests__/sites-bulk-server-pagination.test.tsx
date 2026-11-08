import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { SitesBulkDeleteControl } from '../sites-bulk-delete-control';

const context: FrontendContext = {
    user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['tenant.view', 'sites.view', 'sites.manage'],
    connectors: [], capabilities: {}, api: { sites: '/api/tenants/alpha/sites' }, actions: {},
};
function mount() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(<QueryClientProvider client={client}>
        <LocaleProvider><ToastProvider><SitesBulkDeleteControl context={context} /></ToastProvider></LocaleProvider>
    </QueryClientProvider>);
}
afterEach(() => { vi.unstubAllGlobals(); });

describe('Sites bulk delete authoritative paged selection (#1608)', () => {
    it('reads bounded pages and never carries hidden selections into a destructive confirmation', async () => {
        const requests: URL[] = [];
        const fetchMock = vi.fn(async (input: RequestInfo | URL, options?: RequestInit) => {
            const url = new URL(String(input), 'http://localhost');
            requests.push(url);
            if (options?.method === 'DELETE') throw new Error('No delete should occur');
            const page = Number(url.searchParams.get('page'));
            const perPage = Number(url.searchParams.get('per_page'));
            const start = (page - 1) * perPage;
            const count = Math.min(perPage, 30 - start);
            return new Response(JSON.stringify({
                data: Array.from({ length: count }, (_, i) => ({ id: start + i + 1, name: `Site ${start + i + 1}`, status: 'active' })),
                total: 30, current_page: page, last_page: Math.ceil(30 / perPage), per_page: perPage,
            }), { status: 200, headers: { 'content-type': 'application/json' } });
        });
        vi.stubGlobal('fetch', fetchMock);
        mount();

        expect(await screen.findByRole('checkbox', { name: 'Select Site 1' })).toBeInTheDocument();
        expect(screen.getAllByRole('checkbox')).toHaveLength(25);
        expect(requests[0].searchParams.get('per_page')).toBe('25');
        fireEvent.click(screen.getByRole('checkbox', { name: 'Select Site 1' }));
        expect(screen.getByRole('button', { name: 'Delete selected' })).toBeEnabled();

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(await screen.findByRole('checkbox', { name: 'Select Site 26' })).toBeInTheDocument();
        expect(screen.getAllByRole('checkbox')).toHaveLength(5);
        expect(screen.getByRole('button', { name: 'Delete selected' })).toBeDisabled();
        expect(screen.getByRole('checkbox', { name: 'Select Site 26' })).not.toBeChecked();
        expect(requests[1].searchParams.get('page')).toBe('2');

        fireEvent.click(screen.getByRole('button', { name: 'Select visible' }));
        expect(screen.getByRole('button', { name: 'Delete selected' })).toBeEnabled();
        fireEvent.click(screen.getByRole('button', { name: 'Delete selected' }));
        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByText(/deletion of 5 site profiles/i)).toBeInTheDocument();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        expect(fetchMock.mock.calls.every(([, options]) => !options || options.method !== 'DELETE')).toBe(true);

        fireEvent.change(screen.getByRole('combobox', { name: 'Rows per page' }), { target: { value: '10' } });
        await waitFor(() => expect(requests.at(-1)?.searchParams.get('per_page')).toBe('10'));
        expect(requests.at(-1)?.searchParams.get('page')).toBe('1');
        expect(await screen.findByRole('checkbox', { name: 'Select Site 1' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Delete selected' })).toBeDisabled();
    });
});
