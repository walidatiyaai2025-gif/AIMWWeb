import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { LocaleProvider } from '../i18n';
import {
    SITE_DATA_SNAPSHOT_SYNC_OPERATION_ID,
    SiteDataSnapshotSyncControl,
    siteSnapshotEndpoints,
    syncRunOutcome,
} from '../site-data-snapshot-sync-control';
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
            <MemoryRouter initialEntries={['/tenants/alpha/sites/7/snapshot']}>
                <Routes>
                    <Route path="/tenants/:tenantSlug/sites/:siteId/snapshot" element={<SiteDataSnapshotSyncControl context={active} />} />
                </Routes>
            </MemoryRouter>
        </LocaleProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('AIMW-BILL-2D6F2BC88E Site Data Snapshot SynchronizeAsync', () => {
    it('uses one idempotency key, real queued sync, and authoritative reread before success', async () => {
        const snapshot = {
            operation_id: SITE_DATA_SNAPSHOT_SYNC_OPERATION_ID,
            site: { id: 7, name: 'Alpha Site', connection_status: 'verified', last_sync_at: null },
            cached: { total: 3, by_type: { post: 3 } },
            latest_run: null,
        };
        const reread = {
            ...snapshot,
            site: { ...snapshot.site, last_sync_at: '2026-10-04T09:00:00Z' },
            cached: { total: 5, by_type: { post: 5 } },
            latest_run: { id: 41, status: 'succeeded', processed: 2, failure: null, completed_at: '2026-10-04T09:00:00Z' },
        };
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify(snapshot), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 41, status: 'succeeded' }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify(reread), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);
        vi.stubGlobal('crypto', { randomUUID: () => '11111111-1111-4111-8111-111111111111' });

        renderControl();
        expect(await screen.findByText('Cached records: 3')).toBeInTheDocument();
        const button = screen.getByRole('button', { name: 'Sync now' });
        expect(button).toHaveAttribute('data-canonical-operation', SITE_DATA_SNAPSHOT_SYNC_OPERATION_ID);
        fireEvent.click(button);

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));
        expect(fetchMock.mock.calls[1]?.[0]).toBe('/api/tenants/alpha/sites/7/sync');
        const init = fetchMock.mock.calls[1]?.[1] as RequestInit;
        expect(init.method).toBe('POST');
        expect(new Headers(init.headers).get('Idempotency-Key')).toBe('11111111-1111-4111-8111-111111111111');
        expect(init.body).toBe('{}');
        expect(await screen.findByRole('status')).toHaveTextContent('Synchronization completed');
        expect(await screen.findByText('Cached records: 5')).toBeInTheDocument();
    });

    it('fails closed for invalid site identifiers and hides mutation without sites.manage', async () => {
        expect(siteSnapshotEndpoints('alpha', '../7')).toBeNull();
        expect(siteSnapshotEndpoints('alpha/beta', 7)).toBeNull();
        expect(syncRunOutcome('failed')).toBe('failure');
        expect(SITE_DATA_SNAPSHOT_SYNC_OPERATION_ID).toBe('AIMW-BILL-2D6F2BC88E');

        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            operation_id: SITE_DATA_SNAPSHOT_SYNC_OPERATION_ID,
            site: { id: 7, name: 'Alpha Site', connection_status: 'verified', last_sync_at: null },
            cached: { total: 0, by_type: {} },
            latest_run: null,
        }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);
        renderControl(context(['tenant.view', 'sites.view']));
        expect(await screen.findByText('Cached records: 0')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Sync now' })).not.toBeInTheDocument();
    });
});
