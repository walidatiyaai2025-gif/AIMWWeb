import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { canonicalSitesDeleteCollection, SITES_DELETE_OPERATION_ID, SitesDeleteControl } from '../sites-delete-control';

function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view', 'sites.manage'],
        connectors: [],
        capabilities: {},
        api: { sites: '/api/tenants/alpha/sites' },
        actions: {},
    };
}

function renderControl(activeContext = context()) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider><SitesDeleteControl context={activeContext} /></LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => vi.unstubAllGlobals());

const json = (value: unknown, status = 200) => new Response(JSON.stringify(value), { status, headers: { 'content-type': 'application/json' } });

describe('AIMW-BILL-BE4B8C3822 Sites ConfirmDeleteAsync adaptation', () => {
    it('requires confirmation, derives the DELETE from active tenant state, and announces success only after authoritative reread removes the site', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(json([{ id: 31, name: 'Alpha Site', status: 'connected' }]))
            .mockResolvedValueOnce(new Response(null, { status: 204 }))
            .mockResolvedValueOnce(json([]));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();
        const request = await screen.findByRole('button', { name: 'Delete' });
        fireEvent.click(request);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const confirm = screen.getByRole('button', { name: 'Confirm delete' });
        expect(confirm).toHaveAttribute('data-canonical-operation', SITES_DELETE_OPERATION_ID);
        fireEvent.click(confirm);

        await screen.findByRole('status');
        expect(fetchMock.mock.calls[1]?.[0]).toBe('/api/tenants/alpha/sites/31');
        expect(fetchMock.mock.calls[1]?.[1]).toMatchObject({ method: 'DELETE' });
        expect(fetchMock.mock.calls[2]?.[0]).toBe('/api/tenants/alpha/sites');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('cancel performs no mutation', async () => {
        const fetchMock = vi.fn().mockResolvedValue(json([{ id: 31, name: 'Alpha Site' }]));
        vi.stubGlobal('fetch', fetchMock);
        renderControl();
        fireEvent.click(await screen.findByRole('button', { name: 'Delete' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('fails closed when authoritative reread still contains the deleted site', async () => {
        const row = [{ id: 31, name: 'Alpha Site' }];
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(json(row))
            .mockResolvedValueOnce(new Response(null, { status: 204 }))
            .mockResolvedValueOnce(json(row));
        vi.stubGlobal('fetch', fetchMock);
        renderControl();
        fireEvent.click(await screen.findByRole('button', { name: 'Delete' }));
        fireEvent.click(screen.getByRole('button', { name: 'Confirm delete' }));
        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('authoritative Sites reread still contains the site');
        expect(screen.getByRole('dialog')).toBeInTheDocument();
    });

    it('does not render without sites.manage or with a foreign/malformed collection contract', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const denied = context(); denied.permissions = ['tenant.view', 'sites.view'];
        renderControl(denied);
        expect(screen.queryByLabelText('Delete site')).not.toBeInTheDocument();

        cleanup();
        const foreign = context(); foreign.api.sites = '/api/tenants/beta/sites';
        renderControl(foreign);
        expect(screen.queryByLabelText('Delete site')).not.toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(canonicalSitesDeleteCollection(foreign)).toBeNull();
    });

    it('suppresses duplicate confirmation while the destructive request is pending', async () => {
        let resolveDelete!: (value: Response) => void;
        const deletePromise = new Promise<Response>((resolve) => { resolveDelete = resolve; });
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(json([{ id: 31, name: 'Alpha Site' }]))
            .mockReturnValueOnce(deletePromise)
            .mockResolvedValueOnce(json([]));
        vi.stubGlobal('fetch', fetchMock);
        renderControl();
        fireEvent.click(await screen.findByRole('button', { name: 'Delete' }));
        fireEvent.click(screen.getByRole('button', { name: 'Confirm delete' }));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Deleting…' })).toBeDisabled());
        expect(fetchMock).toHaveBeenCalledTimes(2);
        resolveDelete(new Response(null, { status: 204 }));
        await screen.findByRole('status');
    });
});