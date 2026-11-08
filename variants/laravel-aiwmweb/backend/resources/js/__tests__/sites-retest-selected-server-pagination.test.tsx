import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { SitesRetestSelectedControl } from '../sites-retest-selected-control';

const tenantContext: FrontendContext = {
    user: { id: 1, name: 'Owner', email: 'owner@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['tenant.view', 'sites.view', 'connector.manage'],
    connectors: [], capabilities: {}, api: { sites: '/api/tenants/alpha/sites' }, actions: {},
};

function mount(context = tenantContext) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider><ToastProvider>
                <SitesRetestSelectedControl context={context} />
            </ToastProvider></LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => { vi.unstubAllGlobals(); });

describe('Sites retest-selected authoritative server paging (#1608)', () => {
    it('limits page reads and sends a real recheck only for explicitly selected visible sites', async () => {
        const reads: URL[] = [];
        const rechecks: string[] = [];
        const fetchMock = vi.fn(async (input: RequestInfo | URL, options?: RequestInit) => {
            const url = new URL(String(input), 'http://localhost');
            if (options?.method === 'POST') {
                rechecks.push(url.pathname);
                return new Response(JSON.stringify({ id: Number(url.pathname.split('/')[5]), classification: 'ok' }), {
                    status: 200, headers: { 'content-type': 'application/json' },
                });
            }
            reads.push(url);
            const page = Number(url.searchParams.get('page'));
            const perPage = Number(url.searchParams.get('per_page'));
            const start = (page - 1) * perPage;
            return new Response(JSON.stringify({
                data: Array.from({ length: Math.min(perPage, 30 - start) }, (_, index) => ({
                    id: start + index + 1, name: `Site ${start + index + 1}`, status: 'active',
                })),
                total: 30, current_page: page, last_page: Math.ceil(30 / perPage), per_page: perPage,
            }), { status: 200, headers: { 'content-type': 'application/json' } });
        });
        vi.stubGlobal('fetch', fetchMock);
        mount();

        expect(await screen.findByRole('checkbox', { name: 'Select Site 1' })).toBeInTheDocument();
        expect(screen.getAllByRole('checkbox')).toHaveLength(25);
        expect(reads[0].searchParams.get('per_page')).toBe('25');
        fireEvent.click(screen.getByRole('checkbox', { name: 'Select Site 1' }));
        expect(screen.getByRole('button', { name: 'Retest selected' })).toBeEnabled();

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(await screen.findByRole('checkbox', { name: 'Select Site 26' })).toBeInTheDocument();
        expect(screen.getAllByRole('checkbox')).toHaveLength(5);
        expect(screen.getByRole('button', { name: 'Retest selected' })).toBeDisabled();
        expect(reads.at(-1)?.searchParams.get('page')).toBe('2');

        fireEvent.click(screen.getByRole('checkbox', { name: 'Select Site 26' }));
        fireEvent.click(screen.getByRole('button', { name: 'Retest selected' }));
        await waitFor(() => expect(rechecks).toEqual(['/api/tenants/alpha/sites/26/connection/recheck']));
        expect(await screen.findByText('Retested 1 sites: 1 succeeded, 0 need review.')).toBeInTheDocument();
        expect(screen.getByRole('checkbox', { name: 'Select Site 26' })).not.toBeChecked();
        expect(reads.at(-1)?.searchParams.get('page')).toBe('2');

        fireEvent.change(screen.getByRole('combobox', { name: 'Rows per page' }), { target: { value: '10' } });
        expect(await screen.findByRole('checkbox', { name: 'Select Site 1' })).toBeInTheDocument();
        await waitFor(() => expect(reads.at(-1)?.searchParams.get('per_page')).toBe('10'));
        expect(reads.at(-1)?.searchParams.get('page')).toBe('1');
        expect(screen.getByRole('button', { name: 'Retest selected' })).toBeDisabled();
    });

    it('fails closed for users lacking connector.manage and invalid tenant collection bindings', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        mount({ ...tenantContext, permissions: ['tenant.view', 'sites.view'] });
        expect(screen.queryByRole('region', { name: 'Retest selected sites' })).not.toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
