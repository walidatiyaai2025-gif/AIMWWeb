import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { SITE_DETAILS_RETEST_OPERATION_ID, SiteDetailsRetestControl } from '../site-details-retest-control';

const context: FrontendContext = {
    user: { id: 7, name: 'Operator', email: 'operator@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' }, tenants: [],
    permissions: ['tenant.view', 'sites.view', 'connector.manage'],
    connectors: [], capabilities: {}, api: {}, actions: {},
};
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
const connection = { site: { id: 7 }, connection: 'CONNECTED', latest_diagnostic: { classification: 'DISCONNECTED' } };
function mount(activeContext = context) {
    return render(<LocaleProvider><MemoryRouter initialEntries={['/tenants/alpha/sites/7']}><Routes>
        <Route path="/tenants/:tenantSlug/sites/:siteId" element={<SiteDetailsRetestControl context={activeContext} />} />
    </Routes></MemoryRouter></LocaleProvider>);
}
afterEach(() => { vi.unstubAllGlobals(); vi.restoreAllMocks(); });

describe('AIMW-BILL-D6B3460675 Site Details RetestAsync', () => {
    it('posts with no browser credentials and reports the real classification only after authoritative state/history reread', async () => {
        let finish: (value: Response) => void = () => {};
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(response(connection))
            .mockResolvedValueOnce(response({ items: [] }))
            .mockImplementationOnce(() => new Promise<Response>(resolve => { finish = resolve; }))
            .mockResolvedValueOnce(response(connection))
            .mockResolvedValueOnce(response({ items: [{ id: 1, operation: 'diagnostic_recheck', status: 'failed', message: 'Connector is not paired.' }] }));
        vi.stubGlobal('fetch', fetchMock);
        mount();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        const button = screen.getByRole('button', { name: 'Test saved credentials' });
        expect(button.closest('section')).toHaveAttribute('data-canonical-operation', SITE_DETAILS_RETEST_OPERATION_ID);
        fireEvent.click(button);
        expect(screen.queryByRole('status')).toBeNull();
        expect(button).toBeDisabled();
        finish(response({ classification: 'DISCONNECTED' }));
        expect(await screen.findByRole('status')).toHaveTextContent('DISCONNECTED');
        expect(await screen.findByText(/Connector is not paired/)).toBeInTheDocument();
        expect(fetchMock.mock.calls[2]?.[0]).toBe('/api/tenants/alpha/sites/7/retest');
        expect(fetchMock.mock.calls[2]?.[1]).toMatchObject({ method: 'POST', body: '{}' });
        expect(fetchMock.mock.calls[3]?.[0]).toBe('/api/tenants/alpha/sites/7/connection');
        expect(fetchMock.mock.calls[4]?.[0]).toContain('/sites/7/operations');
    });

    it.each([false, true])('does not fabricate success when the POST or authoritative reread fails (%s)', async (refreshFails) => {
        const fetchMock = vi.fn().mockResolvedValueOnce(response(connection)).mockResolvedValueOnce(response({ items: [] }))
            .mockResolvedValueOnce(refreshFails ? response({}) : response({ message: 'Test denied' }, 403))
            .mockResolvedValueOnce(response({ message: 'Refresh failed' }, 500))
            .mockResolvedValueOnce(response({ items: [] }));
        vi.stubGlobal('fetch', fetchMock);
        mount();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        fireEvent.click(screen.getByRole('button', { name: 'Test saved credentials' }));
        expect(await screen.findByRole('alert')).toBeInTheDocument();
        expect(screen.queryByRole('status')).toBeNull();
        expect(screen.getByRole('button', { name: 'Test saved credentials' })).toBeEnabled();
    });

    it('does not expose the mutation to viewers without connector.manage', () => {
        const fetchMock = vi.fn(); vi.stubGlobal('fetch', fetchMock);
        mount({ ...context, permissions: ['tenant.view', 'sites.view'] });
        expect(screen.queryByRole('button')).toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
