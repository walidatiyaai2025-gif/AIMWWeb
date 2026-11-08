import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { LocaleProvider } from '../i18n';
import type { FrontendContext } from '../core';
import { SiteConnectionCenterSyncControl } from '../site-connection-center-sync-control';

const context: FrontendContext = {
    user: { id: 3, name: 'Reader', email: 'reader@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['tenant.view', 'sites.view'],
    connectors: [], capabilities: {}, api: {}, actions: {},
};

afterEach(() => { vi.unstubAllGlobals(); vi.restoreAllMocks(); });

describe('Site Diagnostics authoritative operation-history pagination (#1611)', () => {
    it('pages through server records and filters without showing another page or tenant', async () => {
        const requests: URL[] = [];
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const url = new URL(String(input), 'http://localhost');
            if (url.pathname.endsWith('/connection')) {
                return new Response(JSON.stringify({
                    site: { id: 7, name: 'Alpha Site', connection_status: 'connected' },
                    connection: 'CONNECTED',
                }), { status: 200, headers: { 'content-type': 'application/json' } });
            }
            requests.push(url);
            const page = Number(url.searchParams.get('page'));
            const perPage = Number(url.searchParams.get('per_page'));
            const failedOnly = url.searchParams.get('status') === 'failed';
            const total = failedOnly ? 6 : 30;
            const start = (page - 1) * perPage;
            const items = Array.from({ length: Math.max(0, Math.min(perPage, total - start)) }, (_, i) => ({
                id: start + i + 1,
                operation: 'synchronization',
                status: failedOnly ? 'failed' : 'succeeded',
                message: `server-event-${start + i + 1}`,
            }));
            return new Response(JSON.stringify({
                items, total, page, last_page: Math.ceil(total / perPage), per_page: perPage,
            }), { status: 200, headers: { 'content-type': 'application/json' } });
        });
        vi.stubGlobal('fetch', fetchMock);

        render(
            <LocaleProvider>
                <MemoryRouter initialEntries={['/tenants/alpha/sites/7/connection']}>
                    <Routes>
                        <Route path="/tenants/:tenantSlug/sites/:siteId/connection"
                            element={<SiteConnectionCenterSyncControl context={context} />} />
                    </Routes>
                </MemoryRouter>
            </LocaleProvider>,
        );

        expect(await screen.findByText('server-event-1')).toBeInTheDocument();
        expect(screen.getByText('server-event-25')).toBeInTheDocument();
        expect(screen.queryByText('server-event-26')).not.toBeInTheDocument();
        expect(requests[0].pathname).toBe('/api/tenants/alpha/sites/7/operations');
        expect(requests[0].searchParams.get('per_page')).toBe('25');
        expect(screen.getByText('Page 1 of 2')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(await screen.findByText('server-event-26')).toBeInTheDocument();
        expect(screen.queryByText('server-event-1')).not.toBeInTheDocument();
        expect(screen.getByText('Page 2 of 2')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Operation status'), { target: { value: 'failed' } });
        await waitFor(() => expect(requests.at(-1)?.searchParams.get('status')).toBe('failed'));
        await waitFor(() => expect(screen.getAllByText('Synchronization failed. Review the safe classification below.')).toHaveLength(6));
        expect(screen.queryByText('server-event-1')).not.toBeInTheDocument();
        expect(screen.getByText('Page 1 of 1')).toBeInTheDocument();
        expect(screen.queryByText('server-event-26')).not.toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Operation type'), { target: { value: 'synchronization' } });
        await waitFor(() => expect(requests.at(-1)?.searchParams.get('operation')).toBe('synchronization'));
        expect(screen.queryByRole('button', { name: 'Sync now' })).not.toBeInTheDocument();
    });
});
