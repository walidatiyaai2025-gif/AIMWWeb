import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    AppBulkActionClearControl,
    APP_BULK_ACTION_CLEAR_OPERATION_ID,
} from '../app-bulk-action-clear-control';
import { ToastProvider } from '../components';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { SitesBulkDeleteControl } from '../sites-bulk-delete-control';

function context(permissions: string[] = ['tenant.view', 'sites.view', 'sites.manage']): FrontendContext {
    return {
        user: { id: 1, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions,
        connectors: [],
        capabilities: {},
        api: { sites: '/api/tenants/alpha/sites' },
        actions: {},
    };
}

function renderSitesControl(frontendContext: FrontendContext = context()) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <ToastProvider>
                    <SitesBulkDeleteControl context={frontendContext} />
                </ToastProvider>
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe(`${APP_BULK_ACTION_CLEAR_OPERATION_ID} AppBulkActionBar ClearAsync`, () => {
    it('only renders for a non-empty selection with a callback and publishes local clear intent', () => {
        const clear = vi.fn();
        const view = render(
            <AppBulkActionClearControl selectedCount={0} onClear={clear} />,
        );

        expect(screen.queryByRole('button')).not.toBeInTheDocument();

        view.rerender(<AppBulkActionClearControl selectedCount={2} />);
        expect(screen.queryByRole('button')).not.toBeInTheDocument();

        view.rerender(
            <AppBulkActionClearControl
                selectedCount={2}
                clearText="Clear selection"
                clearAriaLabel="Clear selected items"
                onClear={clear}
            />,
        );

        const button = screen.getByRole('button', { name: 'Clear selected items' });
        expect(button).toHaveAttribute('data-canonical-operation', APP_BULK_ACTION_CLEAR_OPERATION_ID);
        expect(button).toHaveAttribute('data-source-handler', 'ClearAsync');
        expect(button).toHaveAttribute('type', 'button');

        fireEvent.click(button);
        expect(clear).toHaveBeenCalledOnce();
    });

    it('is disabled while busy and does not publish the clear callback', () => {
        const clear = vi.fn();
        render(
            <AppBulkActionClearControl
                selectedCount={1}
                busy
                clearText="Clear"
                onClear={clear}
            />,
        );

        const button = screen.getByRole('button', { name: 'Clear' });
        expect(button).toBeDisabled();
        fireEvent.click(button);
        expect(clear).not.toHaveBeenCalled();
    });

    it('fails closed before reading tenant sites when sites.manage is absent', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        renderSitesControl(context(['tenant.view', 'sites.view']));

        expect(screen.queryByRole('region', { name: 'Bulk site deletion' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Clear site selection' })).not.toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('clears a real tenant-scoped Sites selection without issuing a mutation request', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify([
            { id: 11, name: 'One', status: 'active' },
            { id: 12, name: 'Two', status: 'active' },
        ]), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderSitesControl();

        const first = await screen.findByRole('checkbox', { name: 'Select One' });
        expect(screen.queryByRole('button', { name: 'Clear site selection' })).not.toBeInTheDocument();

        fireEvent.click(first);
        expect(first).toBeChecked();

        const clear = screen.getByRole('button', { name: 'Clear site selection' });
        expect(clear).toHaveAttribute('data-canonical-operation', APP_BULK_ACTION_CLEAR_OPERATION_ID);
        fireEvent.click(clear);

        expect(first).not.toBeChecked();
        expect(screen.queryByRole('button', { name: 'Clear site selection' })).not.toBeInTheDocument();

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(fetchMock.mock.calls.every(([, options]) => !options || options.method === undefined || options.method === 'GET')).toBe(true);
    });
});
