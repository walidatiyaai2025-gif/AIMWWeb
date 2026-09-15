import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { LocaleProvider } from '../i18n';
import { ToastProvider } from '../components';
import { SITE_DETAILS_DELETE_OPERATION_ID, SiteDetailsDeleteControl } from '../site-details-delete-control';
import type { FrontendContext } from '../core';

const fetchMock = vi.fn();
vi.stubGlobal('fetch', fetchMock);

afterEach(() => fetchMock.mockReset());

const context = (permissions = ['tenant.view', 'sites.view', 'sites.manage']): FrontendContext => ({
    user: { id: 1, name: 'Owner', email: 'owner@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: { 'sites.detail.7': '/api/tenants/alpha/sites/7' },
    actions: {},
});

function renderControl(value = context()) {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    render(
        <QueryClientProvider client={queryClient}>
            <LocaleProvider>
                <ToastProvider>
                    <MemoryRouter initialEntries={['/tenants/alpha/sites/7']}>
                        <Routes>
                            <Route path="/tenants/:tenantSlug/sites/:siteId" element={<SiteDetailsDeleteControl context={value} siteId="7" />} />
                            <Route path="/tenants/:tenantSlug/sites" element={<div>Sites collection</div>} />
                        </Routes>
                    </MemoryRouter>
                </ToastProvider>
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

describe('AIMW-BILL-BE4B8C3822 site deletion', () => {
    it('requires explicit confirmation and sends one DELETE to the authoritative tenant/site endpoint', async () => {
        fetchMock.mockResolvedValueOnce(new Response(null, { status: 204 }));
        renderControl();

        expect(screen.queryByRole('button', { name: 'Confirm delete' })).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
        const confirm = screen.getByRole('button', { name: 'Confirm delete' });
        expect(confirm).toHaveAttribute('data-canonical-operation', SITE_DETAILS_DELETE_OPERATION_ID);
        expect(SITE_DETAILS_DELETE_OPERATION_ID).toBe('AIMW-BILL-BE4B8C3822');

        fireEvent.click(confirm);
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(fetchMock.mock.calls[0][0]).toBe('/api/tenants/alpha/sites/7');
        expect(fetchMock.mock.calls[0][1]).toMatchObject({ method: 'DELETE' });
        await screen.findByText('Sites collection');
    });

    it('fails closed when sites.manage is absent', () => {
        renderControl(context(['tenant.view', 'sites.view']));
        expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument();
    });

    it('fails closed when the advertised endpoint does not exactly match the active tenant and site', () => {
        const foreign = context();
        foreign.api['sites.detail.7'] = '/api/tenants/beta/sites/7';
        renderControl(foreign);
        expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
