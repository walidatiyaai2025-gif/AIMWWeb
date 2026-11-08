import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { SitesDisableSelectedControl } from '../sites-disable-selected-control';

const tenantContext: FrontendContext = {
    user: { id: 1, name: 'Owner', email: 'owner@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['tenant.view', 'sites.view', 'sites.manage'],
    connectors: [], capabilities: {}, api: { sites: '/api/tenants/alpha/sites' }, actions: {},
};

function mount(context = tenantContext) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider><ToastProvider>
                <SitesDisableSelectedControl context={context} />
            </ToastProvider></LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => { vi.unstubAllGlobals(); });

describe('Sites disable-selected authoritative server pagination (#1608)', () => {
    it('bounds page reads and prevents hidden-page selections from entering mutations', async () => {
        const gets: URL[] = [];
        const mutations: number[][] = [];
        const disabled = new Set<number>();
        const fetchMock = vi.fn(async (input: RequestInfo | URL, options?: RequestInit) => {
            const url = new URL(String(input), 'http://localhost');
            if (options?.method === 'POST') {
                const ids = JSON.parse(String(options.body)).ids as number[];
                mutations.push(ids);
                ids.forEach(id => disabled.add(id));
                return new Response(JSON.stringify({
                    requested: ids.length, updated: ids.length, connection_status: 'disabled', ids,
                }), { status: 200, headers: { 'content-type': 'application/json' } });
            }
            gets.push(url);
            const page = Number(url.searchParams.get('page'));
            const perPage = Number(url.searchParams.get('per_page'));
            const start = (page - 1) * perPage;
            return new Response(JSON.stringify({
                data: Array.from({ length: Math.min(perPage, 30 - start) }, (_, i) => ({
                    id: start + i + 1, name: `Site ${start + i + 1}`,
                    connection_status: disabled.has(start + i + 1) ? 'disabled' : 'connected',
                })),
                total: 30, current_page: page, last_page: Math.ceil(30 / perPage), per_page: perPage,
            }), { status: 200, headers: { 'content-type': 'application/json' } });
        });
        vi.stubGlobal('fetch', fetchMock);
        mount();

        expect(await screen.findByRole('checkbox', { name: 'Select Site 1' })).toBeInTheDocument();
        expect(screen.getAllByRole('checkbox')).toHaveLength(25);
        expect(gets[0].searchParams.get('per_page')).toBe('25');
        fireEvent.click(screen.getByRole('checkbox', { name: 'Select Site 1' }));
        expect(screen.getByRole('button', { name: 'Disable selected' })).toBeEnabled();

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(await screen.findByRole('checkbox', { name: 'Select Site 26' })).toBeInTheDocument();
        expect(screen.getAllByRole('checkbox')).toHaveLength(5);
        expect(screen.getByRole('button', { name: 'Disable selected' })).toBeDisabled();
        expect(gets.at(-1)?.searchParams.get('page')).toBe('2');

        fireEvent.click(screen.getByRole('checkbox', { name: 'Select Site 26' }));
        fireEvent.click(screen.getByRole('button', { name: 'Disable selected' }));
        await waitFor(() => expect(mutations).toEqual([[26]]));
        expect(await screen.findByText('Disabled 1 sites.')).toBeInTheDocument();
        expect(screen.getByRole('checkbox', { name: 'Select Site 26' })).not.toBeChecked();
        expect(gets.at(-1)?.searchParams.get('page')).toBe('2');

        fireEvent.change(screen.getByRole('combobox', { name: 'Rows per page' }), { target: { value: '10' } });
        expect(await screen.findByRole('checkbox', { name: 'Select Site 1' })).toBeInTheDocument();
        await waitFor(() => expect(gets.at(-1)?.searchParams.get('per_page')).toBe('10'));
        expect(gets.at(-1)?.searchParams.get('page')).toBe('1');
        expect(screen.getByRole('button', { name: 'Disable selected' })).toBeDisabled();
    });

    it('does not issue a tenant GET or expose the mutation to viewers without manage permission', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        mount({ ...tenantContext, permissions: ['tenant.view', 'sites.view'] });
        expect(screen.queryByRole('region', { name: 'Disable selected sites' })).not.toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
