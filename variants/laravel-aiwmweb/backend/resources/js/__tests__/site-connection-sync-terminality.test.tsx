import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { LocaleProvider } from '../i18n';
import {
    SITE_CONNECTION_SYNC_OPERATION_ID,
    SiteConnectionCenterSyncControl,
    connectionSyncOutcome,
    siteConnectionEndpoints,
} from '../site-connection-sync-control';
import type { FrontendContext } from '../core';

function context(permissions = ['tenant.view', 'sites.view', 'sites.manage']): FrontendContext {
    return {
        user: { id: 7, name: 'Operator', email: 'operator@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions,
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
    };
}

function renderControl(active = context()) {
    return render(
        <LocaleProvider>
            <MemoryRouter initialEntries={['/tenants/alpha/sites/7/connection']}>
                <Routes>
                    <Route path="/tenants/:tenantSlug/sites/:siteId/connection" element={<SiteConnectionCenterSyncControl context={active} />} />
                </Routes>
            </MemoryRouter>
        </LocaleProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('AIMW-BILL-3762C05261 SiteConnectionCenter SynchronizeAsync', () => {
    it('submits once with idempotency and rereads connection state and operation history before success', async () => {
        const initialConnection = { site: { id: 7, name: 'Alpha Site', connection_status: 'verified' }, connection: 'CONNECTED', connector: { identity: 'wp-7', revoked: false } };
        const initialHistory = { items: [] };
        const refreshedConnection = { ...initialConnection, site: { ...initialConnection.site, connection_status: 'healthy' } };
        const refreshedHistory = { items: [{ id: 4, operation: 'synchronization', status: 'succeeded', affected_records: 3 }] };
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify(initialConnection), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify(initialHistory), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 41, site_id: 7, status: 'succeeded', processed: 3 }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify(refreshedConnection), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify(refreshedHistory), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);
        vi.stubGlobal('crypto', { randomUUID: () => '22222222-2222-4222-8222-222222222222' });

        renderControl();
        expect(await screen.findByText('Recent records: 0')).toBeInTheDocument();
        const button = screen.getByRole('button', { name: 'Sync now' });
        expect(button).toHaveAttribute('data-canonical-operation', SITE_CONNECTION_SYNC_OPERATION_ID);
        fireEvent.click(button);

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(5));
        expect(fetchMock.mock.calls[2]?.[0]).toBe('/api/tenants/alpha/sites/7/sync');
        const init = fetchMock.mock.calls[2]?.[1] as RequestInit;
        expect(init.method).toBe('POST');
        expect(new Headers(init.headers).get('Idempotency-Key')).toBe('22222222-2222-4222-8222-222222222222');
        expect(await screen.findByRole('status')).toHaveTextContent('Synchronization completed');
        expect(await screen.findByText('Recent records: 1')).toBeInTheDocument();
    });

    it('fails closed for invalid identifiers, missing manage permission, and missing connector', async () => {
        expect(siteConnectionEndpoints('alpha', '../7')).toBeNull();
        expect(siteConnectionEndpoints('alpha/beta', 7)).toBeNull();
        expect(connectionSyncOutcome('failed')).toBe('failure');
        expect(SITE_CONNECTION_SYNC_OPERATION_ID).toBe('AIMW-BILL-3762C05261');

        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ site: { id: 7, name: 'Alpha Site' }, connection: 'DISCONNECTED', connector: null }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ items: [] }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);
        renderControl(context(['tenant.view', 'sites.view']));
        const button = await screen.findByRole('button', { name: 'Sync now' });
        expect(button).toBeDisabled();
        expect(await screen.findByText('Site management permission is required to synchronize.')).toBeInTheDocument();
    });
});
