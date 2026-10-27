import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITES_SAVING_LABEL_OPERATION_ID,
    SitesSaveControl,
} from '../sites-save-control';

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
    const client = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <SitesSaveControl context={active} />
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('AIMW-CONT-2752ED9351 Sites saving label', () => {
    it('renders the canonical Saving state only while the real Sites save mutation is pending', async () => {
        let resolveSave!: (response: Response) => void;
        const saveResponse = new Promise<Response>((resolve) => {
            resolveSave = resolve;
        });
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(json([]))
            .mockImplementationOnce(() => saveResponse)
            .mockResolvedValueOnce(json([{ id: 31, name: 'Alpha', url: 'https://alpha.test' }]));
        vi.stubGlobal('fetch', fetchMock);
        show();

        fireEvent.change(await screen.findByLabelText('Name'), { target: { value: 'Alpha' } });
        fireEvent.change(screen.getByLabelText('URL'), { target: { value: 'https://alpha.test' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        const saving = await screen.findByText('Saving…');
        expect(saving).toHaveAttribute('data-canonical-operation', SITES_SAVING_LABEL_OPERATION_ID);
        expect(SITES_SAVING_LABEL_OPERATION_ID).toBe('AIMW-CONT-2752ED9351');
        expect(saving).toHaveAttribute('data-source-state', 'saving');
        expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Saving…' })).toHaveAttribute('aria-busy', 'true');
        expect(fetchMock).toHaveBeenCalledTimes(2);

        resolveSave(json({ id: 31, name: 'Alpha', url: 'https://alpha.test' }, 201));

        expect(await screen.findByRole('status')).toHaveTextContent('verified authoritative state');
        expect(screen.queryByText('Saving…')).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    it('does not expose the saving state when the tenant collection is foreign or sites.manage is missing', () => {
        const foreignTenant = context();
        foreignTenant.api.sites = '/api/tenants/beta/sites';
        show(foreignTenant);
        expect(screen.queryByText('Saving…')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Save site')).not.toBeInTheDocument();

        const denied = context();
        denied.permissions = ['tenant.view', 'sites.view'];
        show(denied);
        expect(screen.queryAllByLabelText('Save site')).toHaveLength(0);
    });
});
