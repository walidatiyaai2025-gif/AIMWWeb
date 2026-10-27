import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    OPERATIONS_HUB_RELOAD_OPERATION_ID,
    OperationsHubReloadControl,
} from '../operations-hub-reload-control';

function context(endpoint = '/tenants/alpha/admin/operations-hub'): FrontendContext {
    return {
        user: { id: 1, name: 'Alpha Operator', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['operations.manage', 'execution.view'],
        connectors: [],
        capabilities: {},
        api: { 'operations-hub': endpoint },
        actions: {},
    };
}

function envelope(site: string, operation: string) {
    return {
        data: {
            sites: [{ id: 1, name: site, status: 'active', connection_status: 'paired', health_state: 'healthy' }],
            operations: [{ id: 10, type: operation, status: 'succeeded', updated_at: '2026-10-04T09:00:00Z' }],
        },
        meta: {
            operation_id: OPERATIONS_HUB_RELOAD_OPERATION_ID,
            tenant: 'alpha',
            refreshed_at: '2026-10-04T09:00:00Z',
        },
    };
}

function renderControl(value = context()) {
    return render(<LocaleProvider><OperationsHubReloadControl context={value} /></LocaleProvider>);
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe(`${OPERATIONS_HUB_RELOAD_OPERATION_ID} ReloadAsync`, () => {
    it('loads active-tenant authoritative state and replaces it on explicit refresh using GET only', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify(envelope('Alpha Site Before', 'sync.before')), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify(envelope('Alpha Site After', 'sync.after')), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        expect(await screen.findByText('sync.before')).toBeInTheDocument();
        const refresh = screen.getByRole('button', { name: 'Refresh' });
        expect(refresh).toHaveAttribute('data-canonical-operation', OPERATIONS_HUB_RELOAD_OPERATION_ID);

        fireEvent.click(refresh);

        await waitFor(() => expect(screen.queryByText('sync.before')).not.toBeInTheDocument());
        expect(await screen.findByText('sync.after')).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls.map(([url]) => url)).toEqual([
            '/tenants/alpha/admin/operations-hub',
            '/tenants/alpha/admin/operations-hub',
        ]);
        expect(fetchMock.mock.calls.every(([, options]) => !options || options.method === undefined || options.method === 'GET')).toBe(true);
    });

    it('clears previously displayed state before a failed authoritative refresh and exposes retry without fake success', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify(envelope('Alpha Site', 'sync.current')), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ message: 'storage unavailable' }), { status: 503, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        expect(await screen.findByText('sync.current')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));

        await waitFor(() => expect(screen.queryByText('sync.current')).not.toBeInTheDocument());
        expect(await screen.findByText('Operation history could not be loaded')).toBeInTheDocument();
        expect(screen.getByText('No stale snapshot is presented as current state.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Retry' })).toHaveAttribute(
            'data-canonical-operation',
            OPERATIONS_HUB_RELOAD_OPERATION_ID,
        );
    });

    it('fails closed before network access when the advertised endpoint belongs to another tenant', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        renderControl(context('/tenants/beta/admin/operations-hub'));

        expect(await screen.findByText('Operation history could not be loaded')).toBeInTheDocument();
        expect(screen.getByText('Operations Hub reload endpoint does not belong to the active tenant.')).toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
