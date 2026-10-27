import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITES_CLOSE_FORM_CLICKED_OPERATION_ID,
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

const json = (value: unknown) =>
    new Response(JSON.stringify(value), { status: 200, headers: { 'content-type': 'application/json' } });

function show() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <SitesSaveControl context={context()} />
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => vi.unstubAllGlobals());

describe('AIMW-CONT-B60F3F750B Sites CloseFormClicked', () => {
    it('closes and resets the editable form without a mutation or extra request', async () => {
        const fetchMock = vi.fn().mockResolvedValue(json([]));
        vi.stubGlobal('fetch', fetchMock);
        show();

        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Draft name' } });
        fireEvent.change(screen.getByLabelText('URL'), { target: { value: 'https://draft.test' } });

        const cancel = screen.getByRole('button', { name: 'Cancel' });
        expect(cancel).toHaveAttribute('data-canonical-operation', SITES_CLOSE_FORM_CLICKED_OPERATION_ID);
        expect(cancel).toHaveAttribute('data-source-handler', 'CloseFormClicked');

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        fireEvent.click(cancel);

        expect(screen.queryByLabelText('Name')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('URL')).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        fireEvent.click(screen.getByRole('button', { name: 'Add site' }));
        expect(screen.getByLabelText('Name')).toHaveValue('');
        expect(screen.getByLabelText('URL')).toHaveValue('');
    });

    it('keeps the sibling header close operation distinct', () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(json([])));
        show();

        const headerClose = screen.getByRole('button', { name: 'Close' });
        const cancel = screen.getByRole('button', { name: 'Cancel' });

        expect(headerClose).not.toHaveAttribute('data-canonical-operation', SITES_CLOSE_FORM_CLICKED_OPERATION_ID);
        expect(cancel).toHaveAttribute('data-canonical-operation', 'AIMW-CONT-B60F3F750B');
    });
});
