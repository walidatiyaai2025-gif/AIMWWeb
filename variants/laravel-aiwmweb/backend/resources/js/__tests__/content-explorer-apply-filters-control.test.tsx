import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import {
    authoritativeContentExplorerFilterEndpoint,
    CONTENT_EXPLORER_APPLY_FILTERS_OPERATION_ID,
    ContentExplorerApplyFiltersControl,
} from '../content-explorer-apply-filters-control';
import { LocaleProvider } from '../i18n';

type SiteAwareContext = FrontendContext & {
    active_site?: { id: number; name: string; status: string } | null;
};

function context(overrides: Partial<SiteAwareContext> = {}): SiteAwareContext {
    return {
        user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view'],
        connectors: [],
        capabilities: {},
        api: { explorer: '/api/v1/tenants/alpha/sites/7/explorer' },
        actions: {},
        active_site: { id: 7, name: 'Alpha Site', status: 'active' },
        ...overrides,
    };
}

function renderControl(value: SiteAwareContext = context()) {
    const client = new QueryClient({
        defaultOptions: { queries: { retry: false } },
    });

    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <ContentExplorerApplyFiltersControl context={value} />
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('AIMW-SYNC-419C403EFB ContentExplorer ApplyFiltersAsync', () => {
    it('submits the canonical filters to the authoritative tenant/site explorer GET', async () => {
        expect(CONTENT_EXPLORER_APPLY_FILTERS_OPERATION_ID).toBe('AIMW-SYNC-419C403EFB');

        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            data: [{
                id: 11,
                wordpress_id: 101,
                type: 'post',
                title: 'Needle match',
                slug: 'needle-match',
                status: 'publish',
            }],
            total: 1,
            current_page: 1,
            last_page: 1,
        }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();
        expect(fetchMock).not.toHaveBeenCalled();

        fireEvent.change(screen.getByLabelText('Search query'), { target: { value: 'needle' } });
        fireEvent.change(screen.getByLabelText('Content type'), { target: { value: 'post' } });
        fireEvent.change(screen.getByLabelText('Content status'), { target: { value: 'publish' } });
        fireEvent.change(screen.getByLabelText('Page size'), { target: { value: '25' } });

        const button = screen.getByRole('button', { name: 'Search' });
        expect(button).toHaveAttribute('data-canonical-operation', CONTENT_EXPLORER_APPLY_FILTERS_OPERATION_ID);
        fireEvent.click(button);

        expect(await screen.findByRole('cell', { name: 'Needle match' })).toBeInTheDocument();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(fetchMock.mock.calls[0][0]).toBe(
            '/api/v1/tenants/alpha/sites/7/explorer?search=needle&type=post&status=publish&per_page=25',
        );
        expect(fetchMock.mock.calls[0][1]?.method ?? 'GET').toBe('GET');
    });

    it('loads ten filtered rows by default and allows the second authoritative page', async () => {
        const fetchMock = vi.fn(async (url: string) => {
            const next = String(url).includes('page=2');
            const rows = Array.from({ length: next ? 7 : 10 }, (_, index) => ({
                id: (next ? 10 : 0) + index + 1,
                wordpress_id: (next ? 10 : 0) + index + 1,
                type: 'post',
                title: `Result ${(next ? 10 : 0) + index + 1}`,
                slug: 'result',
                status: 'publish',
            }));
            return new Response(JSON.stringify({
                data: rows,
                total: 17,
                current_page: next ? 2 : 1,
                last_page: 2,
            }), { status: 200, headers: { 'content-type': 'application/json' } });
        });
        vi.stubGlobal('fetch', fetchMock);

        renderControl();
        expect(screen.getByLabelText('Page size')).toHaveValue('10');
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));
        expect(await screen.findByText('Page 1 of 2')).toBeInTheDocument();
        expect(screen.getAllByRole('row')).toHaveLength(11);
        expect(fetchMock.mock.calls[0][0]).toContain('per_page=10');

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(await screen.findByText('Page 2 of 2')).toBeInTheDocument();
        await waitFor(() => expect(screen.getAllByRole('row')).toHaveLength(8));
        expect(fetchMock.mock.calls[1][0]).toContain('per_page=10&page=2');
        expect(screen.getByText('11–17 of 17')).toBeInTheDocument();
    });

    it('surfaces read failure without a fabricated success state', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(
            JSON.stringify({ message: 'Apply failed' }),
            { status: 500, headers: { 'content-type': 'application/json' } },
        )));

        renderControl();
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('Apply failed');
        expect(screen.queryByText(/success/i)).toBeNull();
    });

    it('fails closed for a foreign tenant endpoint before the server 404 isolation boundary', () => {
        expect(authoritativeContentExplorerFilterEndpoint(context({
            api: { explorer: '/api/v1/tenants/beta/sites/7/explorer' },
        }))).toBeNull();

        expect(authoritativeContentExplorerFilterEndpoint(context({
            active_site: { id: 7, name: 'Alpha Site', status: 'active' },
            api: { explorer: '/api/v1/tenants/alpha/sites/99/explorer' },
        }))).toBeNull();

        expect(authoritativeContentExplorerFilterEndpoint(context({
            permissions: ['tenant.view'],
        }))).toBeNull();
    });
});
