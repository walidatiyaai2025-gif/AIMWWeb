import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { SITES_CLOSE_FORM_OPERATION_ID, SitesSaveControl } from '../sites-save-control';

const context = (): FrontendContext => ({
    user: { id: 1, name: 'Owner', email: 'owner@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['tenant.view', 'sites.view', 'sites.manage'],
    connectors: [],
    capabilities: {},
    api: { sites: '/api/tenants/alpha/sites' },
    actions: {},
});

const json = (value: unknown, status = 200) =>
    new Response(JSON.stringify(value), { status, headers: { 'content-type': 'application/json' } });

function show(active = context()) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <SitesSaveControl context={active} />
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => vi.unstubAllGlobals());

describe('AIMW-CONT-FBA95BDC1D Sites CloseForm', () => {
    it('closes locally, clears draft/edit state, and performs no close request', async () => {
        const fetchMock = vi.fn().mockResolvedValue(json([{ id: 7, name: 'Existing', url: 'https://existing.test' }]));
        vi.stubGlobal('fetch', fetchMock);
        show();

        const edit = await screen.findByRole('button', { name: 'Edit' });
        fireEvent.click(edit);
        expect(screen.getByLabelText('Name')).toHaveValue('Existing');

        const close = screen.getByRole('button', { name: 'Close' });
        expect(close).toHaveAttribute('data-canonical-operation', SITES_CLOSE_FORM_OPERATION_ID);
        fireEvent.click(close);

        expect(screen.queryByLabelText('Name')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add site' })).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        fireEvent.click(screen.getByRole('button', { name: 'Add site' }));
        expect(screen.getByLabelText('Name')).toHaveValue('');
        expect(screen.getByLabelText('URL')).toHaveValue('');
    });

    it('fails closed for foreign tenant endpoints or missing sites.manage permission', () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(json([])));

        const foreign = context();
        foreign.api.sites = '/api/tenants/beta/sites';
        show(foreign);
        expect(screen.queryByRole('button', { name: 'Close' })).not.toBeInTheDocument();

        const denied = context();
        denied.permissions = ['tenant.view', 'sites.view'];
        show(denied);
        expect(screen.queryAllByRole('button', { name: 'Close' })).toHaveLength(0);
    });

    it('cannot close while the authoritative save mutation is pending', async () => {
        let resolveSave: ((response: Response) => void) | undefined;
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(json([]))
            .mockImplementationOnce(() => new Promise<Response>((resolve) => { resolveSave = resolve; }));
        vi.stubGlobal('fetch', fetchMock);
        show();

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Alpha' } });
        fireEvent.change(screen.getByLabelText('URL'), { target: { value: 'https://alpha.test' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(screen.getByRole('button', { name: 'Close' })).toBeDisabled());
        expect(screen.getByLabelText('Name')).toBeInTheDocument();

        resolveSave?.(json({ id: 31, name: 'Alpha', url: 'https://alpha.test' }, 201));
    });
});
