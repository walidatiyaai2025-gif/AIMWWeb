import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    APP_DATA_GRID_CLEAR_SELECTION_OPERATION_ID,
    AppDataGridClearSelectionControl,
    clearAppDataGridSelection,
} from '../app-data-grid-clear-selection-control';
import { ToastProvider } from '../components';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { SitesBulkDeleteControl } from '../sites-bulk-delete-control';

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

function renderSites() {
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
    vi.restoreAllMocks();
});

describe(`${APP_DATA_GRID_CLEAR_SELECTION_OPERATION_ID} AppDataGrid ClearSelectionAsync`, () => {
    it('clears local selection before raising SelectionChanged with an empty key collection', async () => {
        const calls: string[] = [];

        await clearAppDataGridSelection({
            clearLocalSelection: () => calls.push('clear-local'),
            onSelectionChanged: (keys) => { calls.push(`selection-changed:${keys.length}`); },
        });

        expect(calls).toEqual(['clear-local', 'selection-changed:0']);
    });

    it('renders only for a non-empty selection and exposes the exact canonical operation', async () => {
        const onClearSelection = vi.fn();
        const onSelectionChanged = vi.fn();

        const { rerender } = render(
            <AppDataGridClearSelectionControl
                locale="en"
                selectedCount={0}
                onClearSelection={onClearSelection}
                onSelectionChanged={onSelectionChanged}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Clear selection' })).not.toBeInTheDocument();

        rerender(
            <AppDataGridClearSelectionControl
                locale="en"
                selectedCount={2}
                onClearSelection={onClearSelection}
                onSelectionChanged={onSelectionChanged}
            />,
        );

        const button = screen.getByRole('button', { name: 'Clear selection' });
        expect(button).toHaveAttribute('data-canonical-operation', APP_DATA_GRID_CLEAR_SELECTION_OPERATION_ID);
        fireEvent.click(button);

        await waitFor(() => expect(onClearSelection).toHaveBeenCalledTimes(1));
        expect(onSelectionChanged).toHaveBeenCalledWith([]);
    });

    it('reuses the existing Sites clear-selection action and never issues a mutation request', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify([
            { id: 11, name: 'One', status: 'active' },
            { id: 12, name: 'Two', status: 'active' },
            { id: 13, name: 'Three', status: 'active' },
        ]), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderSites();

        const checkboxes = await screen.findAllByRole('checkbox');
        expect(checkboxes).toHaveLength(3);

        fireEvent.click(checkboxes[0]);
        fireEvent.click(checkboxes[1]);

        const clear = await screen.findByRole('button', { name: 'Clear selection' });
        expect(clear).toHaveAttribute('data-canonical-operation', APP_DATA_GRID_CLEAR_SELECTION_OPERATION_ID);

        fireEvent.click(clear);

        await waitFor(() => {
            expect(checkboxes[0]).not.toBeChecked();
            expect(checkboxes[1]).not.toBeChecked();
            expect(screen.queryByRole('button', { name: 'Clear selection' })).not.toBeInTheDocument();
        });

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(fetchMock.mock.calls.every(([, options]) => !options || options.method === undefined || options.method === 'GET')).toBe(true);
    });

    it('fails closed while the owning grid is busy', () => {
        const onClearSelection = vi.fn();
        render(
            <AppDataGridClearSelectionControl
                locale="en"
                selectedCount={1}
                busy
                onClearSelection={onClearSelection}
            />,
        );

        const button = screen.getByRole('button', { name: 'Clear selection' });
        expect(button).toBeDisabled();
        fireEvent.click(button);
        expect(onClearSelection).not.toHaveBeenCalled();
    });
});
