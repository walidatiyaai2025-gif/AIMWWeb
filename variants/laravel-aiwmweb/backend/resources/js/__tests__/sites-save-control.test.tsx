import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { canonicalSitesSaveCollection, SITES_SAVE_OPERATION_ID, SitesSaveControl } from '../sites-save-control';

const context = (): FrontendContext => ({
    user: { id: 1, name: 'Owner', email: 'owner@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['tenant.view', 'sites.view', 'sites.manage'],
    connectors: [], capabilities: {},
    api: { sites: '/api/tenants/alpha/sites' }, actions: {},
});
const json = (value: unknown, status = 200) =>
    new Response(JSON.stringify(value), { status, headers: { 'content-type': 'application/json' } });

function show(active = context()) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return render(<QueryClientProvider client={client}><LocaleProvider><SitesSaveControl context={active} /></LocaleProvider></QueryClientProvider>);
}

afterEach(() => vi.unstubAllGlobals());

describe('AIMW-BILL-54E4003BE9 Sites SaveAsync', () => {
    it('creates and verifies an authoritative reread before success', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(json([]))
            .mockResolvedValueOnce(json({ id: 31, name: 'Alpha', url: 'https://alpha.test' }, 201))
            .mockResolvedValueOnce(json([{ id: 31, name: 'Alpha', url: 'https://alpha.test' }]));
        vi.stubGlobal('fetch', fetchMock);
        show();

        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Alpha' } });
        fireEvent.change(screen.getByLabelText('URL'), { target: { value: 'https://alpha.test/' } });
        const save = screen.getByRole('button', { name: 'Save' });
        expect(save).toHaveAttribute('data-canonical-operation', SITES_SAVE_OPERATION_ID);
        fireEvent.click(save);

        expect(await screen.findByRole('status')).toHaveTextContent('verified authoritative state');
        expect(fetchMock.mock.calls[1]?.[0]).toBe('/api/tenants/alpha/sites');
        expect(fetchMock.mock.calls[1]?.[1]).toMatchObject({ method: 'POST' });
        expect(fetchMock.mock.calls[2]?.[0]).toBe('/api/tenants/alpha/sites');
    });

    it('edits through the active tenant collection and rereads it', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(json([{ id: 31, name: 'Old', url: 'https://old.test' }]))
            .mockResolvedValueOnce(json({ id: 31, name: 'New', url: 'https://new.test' }))
            .mockResolvedValueOnce(json([{ id: 31, name: 'New', url: 'https://new.test' }]));
        vi.stubGlobal('fetch', fetchMock);
        show();

        fireEvent.click(await screen.findByRole('button', { name: 'Edit' }));
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'New' } });
        fireEvent.change(screen.getByLabelText('URL'), { target: { value: 'https://new.test' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        await screen.findByRole('status');

        expect(fetchMock.mock.calls[1]?.[0]).toBe('/api/tenants/alpha/sites/31');
        expect(fetchMock.mock.calls[1]?.[1]).toMatchObject({ method: 'PATCH' });
    });

    it('fails closed for a foreign collection or missing manage permission', () => {
        const foreign = context(); foreign.api.sites = '/api/tenants/beta/sites';
        show(foreign);
        expect(screen.queryByLabelText('Save site')).not.toBeInTheDocument();
        expect(canonicalSitesSaveCollection(foreign)).toBeNull();

        const denied = context(); denied.permissions = ['tenant.view', 'sites.view'];
        show(denied);
        expect(screen.queryAllByLabelText('Save site')).toHaveLength(0);
    });

    it('does not report success when the authoritative reread disagrees', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(json([]))
            .mockResolvedValueOnce(json({ id: 31, name: 'Alpha', url: 'https://alpha.test' }, 201))
            .mockResolvedValueOnce(json([{ id: 31, name: 'Different', url: 'https://alpha.test' }]));
        vi.stubGlobal('fetch', fetchMock);
        show();
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Alpha' } });
        fireEvent.change(screen.getByLabelText('URL'), { target: { value: 'https://alpha.test' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('authoritative Sites reread did not match');
        expect(screen.queryByRole('status')).toBeNull();
    });
});