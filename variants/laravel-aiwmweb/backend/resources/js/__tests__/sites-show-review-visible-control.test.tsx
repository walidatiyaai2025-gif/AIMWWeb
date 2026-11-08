import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';

const OPERATION_ID = 'AIMW-CONT-CA17F05024';

function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view'],
        connectors: [],
        capabilities: {},
        api: { sites: '/api/tenants/alpha/sites' },
        actions: {},
    };
}

const route: WorkspaceRoute = {
    key: 'sites',
    path: '/sites',
    group: 'overview',
    icon: '◉',
    label: { en: 'Sites', ar: 'المواقع' },
    description: { en: 'Manage connected WordPress sites.', ar: 'إدارة مواقع WordPress المتصلة.' },
    apiKey: 'sites',
    permission: 'sites.view',
    controls: [],
    kind: 'resource',
};

function renderWorkspace() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
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

function result(rows: Array<Record<string, unknown>>, connectedCount: number, reviewCount: number) {
    return new Response(JSON.stringify({
        data: rows, total: rows.length, current_page: 1, last_page: 1, per_page: 25,
        meta: { connected_count: connectedCount, review_count: reviewCount },
    }), { status: 200, headers: { 'content-type': 'application/json' } });
}

afterEach(() => { vi.unstubAllGlobals(); });

function stubPagedSites(rows: Array<{ id: number; name: string; status: string; connection_status: string }>) {
    const counts = {
        connected_count: rows.filter(row => row.connection_status === 'connected').length,
        review_count: rows.filter(row => row.connection_status !== 'connected' && row.connection_status !== 'unknown' && row.connection_status !== '').length,
    };
    return vi.fn(async (input: RequestInfo | URL) => {
        const url = new URL(String(input), 'http://localhost');
        const filter = url.searchParams.get('connection_filter') ?? 'all';
        const selected = rows.filter(row => filter === 'connected'
            ? row.connection_status === 'connected'
            : filter === 'review'
                ? row.connection_status !== 'connected' && row.connection_status !== 'unknown' && row.connection_status !== ''
                : true);
        return new Response(JSON.stringify({
            data: selected,
            total: selected.length,
            current_page: 1,
            last_page: 1,
            per_page: 25,
            meta: counts,
        }), { status: 200, headers: { 'content-type': 'application/json' } });
    });
}

describe('AIMW-CONT-CA17F05024 server-filtered Sites', () => {
    it('fetches the authoritative review page and preserves accessible filter state', async () => {
        const fetchMock = stubPagedSites([
          {
                    "id": 11,
                    "name": "Connected site",
                    "status": "active",
                    "connection_status": "connected"
          },
          {
                    "id": 12,
                    "name": "Authentication failed site",
                    "status": "active",
                    "connection_status": "authentication_failed"
          },
          {
                    "id": 13,
                    "name": "Degraded site",
                    "status": "active",
                    "connection_status": "degraded"
          },
          {
                    "id": 14,
                    "name": "Unknown site",
                    "status": "active",
                    "connection_status": "unknown"
          }
]);
        vi.stubGlobal('fetch', fetchMock);
        renderWorkspace();

        expect(await screen.findByText('Authentication failed site')).toBeInTheDocument();
        const button = screen.getByRole('button', { name: 'Needs review 2' });
        expect(button).toHaveAttribute('data-canonical-operation', 'AIMW-CONT-CA17F05024');
        expect(button).toHaveAttribute('aria-pressed', 'false');

        fireEvent.click(button);

        await waitFor(() => expect(button).toHaveAttribute('aria-pressed', 'true'));
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(screen.getByText('Authentication failed site')).toBeInTheDocument();
        expect(screen.getByText('Degraded site')).toBeInTheDocument();
        expect(screen.queryByText('Connected site')).not.toBeInTheDocument();
        expect(screen.queryByText('Unknown site')).not.toBeInTheDocument();
        const filteredUrl = String(fetchMock.mock.calls[1][0]);
        expect(new URL(filteredUrl, 'http://localhost').searchParams.get('connection_filter')).toBe('review');
        expect(fetchMock.mock.calls.every(([, options]) => !options || options.method === undefined || options.method === 'GET')).toBe(true);
    });

    it('renders a truthful empty response for the review filter', async () => {
        const fetchMock = stubPagedSites([
          {
                    "id": 21,
                    "name": "Connected site",
                    "status": "active",
                    "connection_status": "connected"
          },
          {
                    "id": 22,
                    "name": "Unknown site",
                    "status": "active",
                    "connection_status": "unknown"
          }
]);
        vi.stubGlobal('fetch', fetchMock);
        renderWorkspace();

        const button = await screen.findByRole('button', { name: 'Needs review 0' });
        fireEvent.click(button);
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(await screen.findByText('No sample rows are synthesized when the server returns an empty result.')).toBeInTheDocument();
        expect(button).toHaveAttribute('aria-pressed', 'true');
    });
});
