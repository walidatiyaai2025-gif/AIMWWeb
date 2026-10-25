import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SYSTEM_HEALTH_REFRESH_OPERATION_ID,
    SystemHealthRefreshControl,
} from '../system-health-refresh-control';
import { SystemHealthWorkspace } from '../system-health-workspace';
import type { SystemHealthSnapshot } from '../system-health-copy-summary-control';

const context = (
    permissions: string[] = ['tenant.view', 'diagnostics.view'],
    tenantSlugs: string[] = ['alpha'],
): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: tenantSlugs.map((slug) => ({ slug, name: slug })),
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

const route = {
    key: 'system-health',
    permission: 'diagnostics.view',
} as WorkspaceRoute;

const snapshot = (status: 'ready' | 'not_ready'): SystemHealthSnapshot => ({
    tenant: 'alpha',
    status,
    checks: {
        app: { status: 'ok' },
        database: { status: status === 'ready' ? 'ok' : 'unavailable' },
        redis: { status: 'ok' },
        storage: { status: 'ok' },
        queue: { status: 'ok' },
        scheduler: { status: 'ok' },
    },
});

function renderWorkspace(activeContext = context()) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <SystemHealthWorkspace context={activeContext} route={route} />
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    cleanup();
    localStorage.clear();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('AIMW-SYNC-0DFEE862FA System Health RefreshClickedAsync parity', () => {
    it('re-reads the authoritative tenant System Health endpoint without mutation', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify(snapshot('ready')), {
                status: 200,
                headers: { 'content-type': 'application/json' },
            }))
            .mockResolvedValueOnce(new Response(JSON.stringify(snapshot('not_ready')), {
                status: 200,
                headers: { 'content-type': 'application/json' },
            }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        expect(await screen.findByText('ready')).toBeInTheDocument();
        const refresh = screen.getByRole('button', { name: 'Run checks' });
        expect(refresh).toHaveAttribute('data-canonical-operation', SYSTEM_HEALTH_REFRESH_OPERATION_ID);
        expect(SYSTEM_HEALTH_REFRESH_OPERATION_ID).toBe('AIMW-SYNC-0DFEE862FA');

        fireEvent.click(refresh);

        expect(await screen.findByText('not_ready')).toBeInTheDocument();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(fetchMock.mock.calls.map(([url]) => url)).toEqual([
            '/api/tenants/alpha/system-health',
            '/api/tenants/alpha/system-health',
        ]);
        expect(fetchMock.mock.calls.every(([, options]) => !options || options.method === undefined || options.method === 'GET')).toBe(true);
    });

    it('keeps Run checks available after a real read failure and recovers by retrying the same endpoint', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ message: 'diagnostics unavailable' }), {
                status: 503,
                headers: { 'content-type': 'application/json' },
            }))
            .mockResolvedValueOnce(new Response(JSON.stringify(snapshot('ready')), {
                status: 200,
                headers: { 'content-type': 'application/json' },
            }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        expect(await screen.findByText('System health could not be loaded')).toBeInTheDocument();
        const retry = screen.getByRole('button', { name: 'Run checks' });
        fireEvent.click(retry);

        expect(await screen.findByText('ready')).toBeInTheDocument();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    });

    it('suppresses duplicate refresh clicks while an authoritative reread is pending', async () => {
        let finish!: () => void;
        const onRefresh = vi.fn(() => new Promise<void>((resolve) => { finish = resolve; }));
        render(
            <LocaleProvider>
                <SystemHealthRefreshControl context={context()} onRefresh={onRefresh} />
            </LocaleProvider>,
        );

        const button = screen.getByRole('button', { name: 'Run checks' });
        fireEvent.click(button);
        fireEvent.click(button);

        expect(onRefresh).toHaveBeenCalledTimes(1);
        expect(button).toBeDisabled();
        expect(button).toHaveTextContent('Checking...');

        finish();
        await waitFor(() => expect(button).toBeEnabled());
    });

    it('fails closed for missing diagnostics permission or a foreign active tenant', () => {
        const missingPermission = render(
            <LocaleProvider>
                <SystemHealthRefreshControl context={context(['tenant.view'])} onRefresh={vi.fn()} />
            </LocaleProvider>,
        );
        expect(screen.queryByRole('button', { name: 'Run checks' })).not.toBeInTheDocument();
        missingPermission.unmount();

        render(
            <LocaleProvider>
                <SystemHealthRefreshControl context={context(undefined, ['beta'])} onRefresh={vi.fn()} />
            </LocaleProvider>,
        );
        expect(screen.queryByRole('button', { name: 'Run checks' })).not.toBeInTheDocument();
    });

    it('preserves Arabic parity for idle and busy states', () => {
        localStorage.setItem('aiwm.locale', 'ar');
        render(
            <LocaleProvider>
                <SystemHealthRefreshControl context={context()} refreshing={true} onRefresh={vi.fn()} />
            </LocaleProvider>,
        );

        expect(screen.getByRole('button', { name: 'جارٍ الفحص...' })).toBeDisabled();
    });
});
