import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { LocaleProvider } from '../i18n';
import {
    SITE_CONNECTION_CENTER_SYNC_OPERATION_ID,
    SiteConnectionCenterSyncControl,
    connectionCenterSyncOutcome,
    siteConnectionCenterEndpoints,
} from '../site-connection-center-sync-control';
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
                    <Route
                        path="/tenants/:tenantSlug/sites/:siteId/connection"
                        element={<SiteConnectionCenterSyncControl context={active} />}
                    />
                </Routes>
            </MemoryRouter>
        </LocaleProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('AIMW-BILL-3762C05261 Site Connection Center SynchronizeAsync', () => {
    it('runs one idempotent sync and rereads authoritative connection plus operation history', async () => {
        const initialConnection = {
            site: { id: 7, name: 'Alpha Site', connection_status: 'connected' },
            connection: 'CONNECTED',
            last_verified_at: null,
        };
        const rereadConnection = {
            ...initialConnection,
            last_verified_at: '2026-10-07T11:00:00Z',
        };
        const initialOperations = {
            items: [{
                id: 10,
                operation: 'diagnostic_recheck',
                status: 'succeeded',
                message: 'Connection checked.',
                affected_records: null,
                started_at: '2026-10-07T10:00:00Z',
            }],
        };
        const rereadOperations = {
            items: [{
                id: 11,
                operation: 'synchronization',
                status: 'succeeded',
                message: 'Synchronization completed.',
                affected_records: 4,
                started_at: '2026-10-07T11:00:00Z',
            }, ...initialOperations.items],
        };

        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify(initialConnection), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify(initialOperations), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 41, status: 'succeeded', processed: 4 }), { status: 202, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify(rereadConnection), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify(rereadOperations), { status: 200, headers: { 'content-type': 'application/json' } }));

        vi.stubGlobal('fetch', fetchMock);
        vi.stubGlobal('crypto', { randomUUID: () => '22222222-2222-4222-8222-222222222222' });

        renderControl();

        expect(await screen.findByText('Alpha Site')).toBeInTheDocument();
        expect(await screen.findByText('diagnostic_recheck')).toBeInTheDocument();

        const button = screen.getByRole('button', { name: 'Sync now' });
        expect(button).toHaveAttribute('data-canonical-operation', SITE_CONNECTION_CENTER_SYNC_OPERATION_ID);
        fireEvent.click(button);

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(5));

        expect(fetchMock.mock.calls[2]?.[0]).toBe('/api/tenants/alpha/sites/7/sync');
        const init = fetchMock.mock.calls[2]?.[1] as RequestInit;
        expect(init.method).toBe('POST');
        expect(new Headers(init.headers).get('Idempotency-Key')).toBe('22222222-2222-4222-8222-222222222222');
        expect(init.body).toBe('{}');

        expect(await screen.findByRole('status')).toHaveTextContent('Synchronization completed');
        expect(await screen.findByText('synchronization')).toBeInTheDocument();
        expect(screen.getByText('Affected records: 4')).toBeInTheDocument();
    });

    it('fails closed for invalid identifiers and hides mutation without sites.manage', async () => {
        expect(siteConnectionCenterEndpoints('alpha', '../7')).toBeNull();
        expect(siteConnectionCenterEndpoints('alpha/beta', 7)).toBeNull();
        expect(connectionCenterSyncOutcome('cancelled')).toBe('failure');
        expect(connectionCenterSyncOutcome('cancel_requested')).toBe('failure');
        expect(SITE_CONNECTION_CENTER_SYNC_OPERATION_ID).toBe('AIMW-BILL-3762C05261');

        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({
                site: { id: 7, name: 'Alpha Site', connection_status: 'connected' },
                connection: 'CONNECTED',
                last_verified_at: null,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ items: [] }), { status: 200, headers: { 'content-type': 'application/json' } }));

        vi.stubGlobal('fetch', fetchMock);
        renderControl(context(['tenant.view', 'sites.view']));

        expect(await screen.findByText('Alpha Site')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Sync now' })).not.toBeInTheDocument();
    });
});
