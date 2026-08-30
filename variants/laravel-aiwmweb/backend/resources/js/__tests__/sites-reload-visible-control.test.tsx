import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { SitesBulkDeleteControl } from '../sites-bulk-delete-control';

const OPERATION_ID = 'AIMW-SYNC-A9E956A4DA';

function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view', 'sites.manage'],
        connectors: [],
        capabilities: {},
        api: { sites: '/api/tenants/alpha/sites' },
        actions: {},
    };
}

function renderControl() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <ToastProvider>
                    <SitesBulkDeleteControl context={context()} />
                </ToastProvider>
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe(`${OPERATION_ID} ReloadClickedAsync`, () => {
    it('rereads the authoritative tenant sites endpoint without issuing a mutation', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify([
            { id: 11, name: 'One', status: 'active' },
            { id: 12, name: 'Two', status: 'active' },
        ]), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        const refresh = await screen.findByRole('button', { name: 'Refresh' });
        expect(refresh).toHaveAttribute('data-canonical-operation', OPERATION_ID);
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

        fireEvent.click(refresh);
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(fetchMock.mock.calls.every(([, options]) => !options || options.method === undefined || options.method === 'GET')).toBe(true);
    });
});
