import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { SITES_OPEN_ADD_FORM_OPERATION_ID, SitesSaveControl } from '../sites-save-control';

const context: FrontendContext = {
    user: { id: 1, name: 'Owner', email: 'owner@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['tenant.view', 'sites.view', 'sites.manage'],
    connectors: [], capabilities: {},
    api: { sites: '/api/tenants/alpha/sites' },
    actions: {},
};

describe('AIMW-CONT-38FC74B9DE Sites OpenAddFormClicked', () => {
    it('reopens a clean add form locally after CloseForm', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('[]', { status: 200, headers: { 'content-type': 'application/json' } })));
        const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        render(<QueryClientProvider client={client}><LocaleProvider><SitesSaveControl context={context} /></LocaleProvider></QueryClientProvider>);

        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'draft' } });
        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        const open = screen.getByRole('button', { name: 'Add site' });
        expect(open).toHaveAttribute('data-canonical-operation', SITES_OPEN_ADD_FORM_OPERATION_ID);
        fireEvent.click(open);
        expect(screen.getByLabelText('Name')).toHaveValue('');
        expect(screen.getByLabelText('URL')).toHaveValue('');
    });
});
