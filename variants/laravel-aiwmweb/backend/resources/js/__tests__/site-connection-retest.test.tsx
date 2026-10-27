import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { SITE_CONNECTION_CENTER_RETEST_OPERATION_ID, SiteConnectionCenterSyncControl } from '../site-connection-center-sync-control';

const context: FrontendContext = {
    user: { id: 7, name: 'Operator', email: 'operator@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['tenant.view', 'sites.view', 'connector.manage'],
    connectors: [], capabilities: {}, api: {}, actions: {},
};

afterEach(() => { vi.unstubAllGlobals(); vi.restoreAllMocks(); });

describe('AIMW-BILL-E747D0E843 RetestAsync', () => {
    it('runs the real tenant-scoped recheck then rereads authoritative state and history', async () => {
        const connection = { site: { id: 7, name: 'Alpha Site', connection_status: 'connected' }, connection: 'CONNECTED' };
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify(connection), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ items: [] }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 55, classification: 'connected' }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify(connection), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ items: [{ id: 1, operation: 'diagnostic_recheck', status: 'succeeded', message: 'Site diagnostic recheck completed.' }] }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        render(<LocaleProvider><MemoryRouter initialEntries={['/tenants/alpha/sites/7/connection']}><Routes>
            <Route path="/tenants/:tenantSlug/sites/:siteId/connection" element={<SiteConnectionCenterSyncControl context={context} />} />
        </Routes></MemoryRouter></LocaleProvider>);

        const button = await screen.findByRole('button', { name: 'Run test' });
        expect(button).toHaveAttribute('data-canonical-operation', SITE_CONNECTION_CENTER_RETEST_OPERATION_ID);
        expect(SITE_CONNECTION_CENTER_RETEST_OPERATION_ID).toBe('AIMW-BILL-E747D0E843');
        fireEvent.click(button);

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(5));
        expect(fetchMock.mock.calls[2]?.[0]).toBe('/api/tenants/alpha/sites/7/connection/recheck');
        expect(fetchMock.mock.calls[2]?.[1]).toMatchObject({ method: 'POST' });
        expect(await screen.findByRole('status')).toHaveTextContent('Connection test completed');
        expect(await screen.findByText('diagnostic_recheck')).toBeInTheDocument();
    });
});
