import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { canonicalSitesRetestCollection, canonicalSitesRetestEndpoint, SITES_RETEST_SELECTED_OPERATION_ID, SitesRetestSelectedControl } from '../sites-retest-selected-control';

const context = (): FrontendContext => ({
    user: { id: 1, name: 'Owner', email: 'owner@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['tenant.view', 'sites.view', 'connector.manage'],
    connectors: [], capabilities: {},
    api: { sites: '/api/tenants/alpha/sites' }, actions: {},
});
const json = (value: unknown, status = 200) => new Response(JSON.stringify(value), { status, headers: { 'content-type': 'application/json' } });
function show(active = context()) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return render(<QueryClientProvider client={client}><LocaleProvider><SitesRetestSelectedControl context={active} /></LocaleProvider></QueryClientProvider>);
}
afterEach(() => vi.unstubAllGlobals());

describe('AIMW-BILL-DD0FD9926B Sites RetestSelectedAsync', () => {
    it('derives only tenant-scoped recheck endpoints', () => {
        expect(canonicalSitesRetestEndpoint('/api/tenants/alpha/sites', 7)).toBe('/api/tenants/alpha/sites/7/connection/recheck');
        expect(canonicalSitesRetestEndpoint('/api/tenants/beta/sites?x=1', 7)).toBeNull();
        expect(canonicalSitesRetestEndpoint('/api/tenants/alpha/sites', 0)).toBeNull();
        const foreign = context(); foreign.api.sites = '/api/tenants/beta/sites';
        expect(canonicalSitesRetestCollection(foreign)).toBeNull();
    });

    it('retests every selected site then rereads authoritative Sites before success', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(json([{ id: 11, name: 'One', status: 'active' }, { id: 12, name: 'Two', status: 'active' }]))
            .mockResolvedValueOnce(json({ id: 11, classification: 'healthy' }))
            .mockResolvedValueOnce(json({ id: 12, classification: 'healthy' }))
            .mockResolvedValueOnce(json([{ id: 11, name: 'One', status: 'active' }, { id: 12, name: 'Two', status: 'active' }]));
        vi.stubGlobal('fetch', fetchMock);
        show();
        fireEvent.click(await screen.findByLabelText('Select One'));
        fireEvent.click(screen.getByLabelText('Select Two'));
        const retest = screen.getByRole('button', { name: 'Retest selected' });
        expect(retest).toHaveAttribute('data-canonical-operation', SITES_RETEST_SELECTED_OPERATION_ID);
        fireEvent.click(retest);
        expect(await screen.findByRole('status')).toHaveTextContent('Retested 2 sites: 2 succeeded, 0 need review.');
        expect(fetchMock.mock.calls[1]?.[0]).toBe('/api/tenants/alpha/sites/11/connection/recheck');
        expect(fetchMock.mock.calls[2]?.[0]).toBe('/api/tenants/alpha/sites/12/connection/recheck');
        expect(fetchMock.mock.calls[3]?.[0]).toBe('/api/tenants/alpha/sites');
    });

    it('reports partial failures after authoritative reread', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(json([{ id: 11, name: 'One', status: 'active' }, { id: 12, name: 'Two', status: 'active' }]))
            .mockResolvedValueOnce(json({ id: 11, classification: 'healthy' }))
            .mockResolvedValueOnce(json({ message: 'unreachable' }, 502))
            .mockResolvedValueOnce(json([{ id: 11, name: 'One', status: 'active' }, { id: 12, name: 'Two', status: 'active' }]));
        vi.stubGlobal('fetch', fetchMock);
        show();
        fireEvent.click(await screen.findByLabelText('Select One'));
        fireEvent.click(screen.getByLabelText('Select Two'));
        fireEvent.click(screen.getByRole('button', { name: 'Retest selected' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Retested 2 sites: 1 succeeded, 1 need review.');
    });

    it('does not render without connector authority or for a foreign collection', () => {
        const denied = context(); denied.permissions = ['tenant.view', 'sites.view'];
        show(denied);
        expect(screen.queryByLabelText('Retest selected sites')).toBeNull();
        const foreign = context(); foreign.api.sites = '/api/tenants/beta/sites';
        show(foreign);
        expect(screen.queryAllByLabelText('Retest selected sites')).toHaveLength(0);
    });
});
