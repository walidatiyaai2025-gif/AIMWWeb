import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';

const OPERATION_ID = 'AIMW-CONT-E346398EAD';

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

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe(`${OPERATION_ID} ShowConnectedSites — server paging`, () => {
    it('filters by real authoritative connection_status, not operational status with tenant-scoped read-only GET and verified totals', async () => {
        const initial = [{"id":11,"name":"Connected but inactive","status":"inactive","connection_status":"connected"},{"id":12,"name":"Active but failed","status":"active","connection_status":"authentication_failed"},{"id":13,"name":"Active unknown","status":"active","connection_status":"unknown"}];
        const filtered = [{"id":11,"name":"Connected but inactive","status":"inactive","connection_status":"connected"}];
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(result(initial, 1, 2))
            .mockResolvedValueOnce(result(filtered, 1, 2));
        vi.stubGlobal('fetch', fetchMock);
        renderWorkspace();

        expect(await screen.findByText('Connected but inactive')).toBeInTheDocument();
        expect(await screen.findByText('Active but failed')).toBeInTheDocument();
        expect(await screen.findByText('Active unknown')).toBeInTheDocument();
        const control = screen.getByRole('button', { name: 'Connected 1' });
        expect(control).toHaveAttribute('data-canonical-operation', OPERATION_ID);
        expect(control).toHaveAttribute('aria-pressed', 'false');
        fireEvent.click(control);

        await waitFor(() => expect(control).toHaveAttribute('aria-pressed', 'true'));
        expect(await screen.findByText('Connected but inactive')).toBeInTheDocument();
        await waitFor(() => expect(screen.queryByText('Active but failed')).not.toBeInTheDocument());
        await waitFor(() => expect(screen.queryByText('Active unknown')).not.toBeInTheDocument());
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(fetchMock.mock.calls[0][0]).toBe('/api/tenants/alpha/sites?page=1&per_page=25&connection_filter=all');
        expect(fetchMock.mock.calls[1][0]).toBe('/api/tenants/alpha/sites?page=1&per_page=25&connection_filter=connected');
        expect(fetchMock.mock.calls.every(([, options]) => !options || !options.method || options.method === 'GET')).toBe(true);
    });

    it('shows the existing empty state when authoritative connected result is empty', async () => {
        const initial = [{"id":21,"name":"Failed site","status":"active","connection_status":"authentication_failed"},{"id":22,"name":"Unknown site","status":"active","connection_status":"unknown"}];
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(result(initial, 0, 1))
            .mockResolvedValueOnce(result([], 0, 1));
        vi.stubGlobal('fetch', fetchMock);
        renderWorkspace();

        const control = await screen.findByRole('button', { name: 'Connected 0' });
        fireEvent.click(control);
        expect(await screen.findByText('No sample rows are synthesized when the server returns an empty result.')).toBeInTheDocument();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(fetchMock.mock.calls[1][0]).toBe('/api/tenants/alpha/sites?page=1&per_page=25&connection_filter=connected');
    });
});
