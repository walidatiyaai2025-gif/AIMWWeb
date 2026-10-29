import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITE_DATA_SNAPSHOT_NAVIGATION_OPERATION_ID,
    SITE_DATA_SNAPSHOT_SYNC_OPERATION_ID,
    SiteDataSnapshotSyncControl,
    siteDataSnapshotNavigationHrefs,
} from '../site-data-snapshot-sync-control';

function context(): FrontendContext {
    return {
        user: { id: 7, name: 'Operator', email: 'operator@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view'],
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
    };
}

function renderControl() {
    return render(
        <LocaleProvider>
            <MemoryRouter initialEntries={['/tenants/alpha/sites/7/snapshot']}>
                <Routes>
                    <Route
                        path="/tenants/:tenantSlug/sites/:siteId/snapshot"
                        element={<SiteDataSnapshotSyncControl context={context()} />}
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

describe('AIMW-CONT-6187F8DF8A SiteDataSnapshot dynamic navigation', () => {
    it('renders tenant-qualified dashboard and explorer links only after the authoritative snapshot loads', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            operation_id: SITE_DATA_SNAPSHOT_SYNC_OPERATION_ID,
            site: { id: 7, name: 'Alpha Site', connection_status: 'verified', last_sync_at: null },
            cached: { total: 4, by_type: { post: 4 } },
            latest_run: null,
        }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        expect(screen.queryByRole('link', { name: 'Site dashboard' })).not.toBeInTheDocument();

        const dashboard = await screen.findByRole('link', { name: 'Site dashboard' });
        const explorer = screen.getByRole('link', { name: 'Content explorer' });

        expect(SITE_DATA_SNAPSHOT_NAVIGATION_OPERATION_ID).toBe('AIMW-CONT-6187F8DF8A');
        expect(dashboard).toHaveAttribute('href', '/tenants/alpha/sites/7');
        expect(explorer).toHaveAttribute('href', '/tenants/alpha/sites/7/explorer');
        expect(dashboard).toHaveAttribute('data-canonical-operation', SITE_DATA_SNAPSHOT_NAVIGATION_OPERATION_ID);
        expect(explorer).toHaveAttribute('data-canonical-operation', SITE_DATA_SNAPSHOT_NAVIGATION_OPERATION_ID);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/tenants/alpha/sites/7/snapshot');
        expect((fetchMock.mock.calls[0]?.[1] as RequestInit | undefined)?.method).toBeUndefined();
        expect(screen.queryByRole('button', { name: 'Sync now' })).not.toBeInTheDocument();
    });

    it('keeps dynamic navigation hidden when the authoritative site is missing', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(
            JSON.stringify({ message: 'Site not found.' }),
            { status: 404, headers: { 'content-type': 'application/json' } },
        ));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        expect(await screen.findByRole('alert')).toHaveTextContent(/site/i);
        expect(screen.queryByRole('link', { name: 'Site dashboard' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Content explorer' })).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('rejects caller-controlled site or tenant path traversal before rendering navigation', () => {
        expect(siteDataSnapshotNavigationHrefs('alpha', '../7')).toBeNull();
        expect(siteDataSnapshotNavigationHrefs('alpha/beta', 7)).toBeNull();
        expect(siteDataSnapshotNavigationHrefs('alpha', 7)).toEqual({
            dashboard: '/tenants/alpha/sites/7',
            explorer: '/tenants/alpha/sites/7/explorer',
        });
    });
});
