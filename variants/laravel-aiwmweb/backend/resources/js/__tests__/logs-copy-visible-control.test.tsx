import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    formatVisibleLogs,
    LOGS_COPY_VISIBLE_OPERATION_ID,
    LogsClearFiltersControl,
} from '../logs-clear-filters-control';

const context = (overrides: Partial<FrontendContext> = {}): FrontendContext => ({
    user: { id: 1, name: 'Logs Operator', email: 'logs@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['tenant.view', 'operations.manage', 'diagnostics.view'],
    connectors: [],
    capabilities: {},
    api: { logs: '/tenants/alpha/admin/logs' },
    actions: {},
    ...overrides,
});

function response(payload: unknown, status = 200) {
    return {
        ok: status >= 200 && status < 300,
        status,
        headers: { get: () => 'application/json' },
        json: async () => payload,
        text: async () => JSON.stringify(payload),
    } as unknown as Response;
}

function renderControl(value = 'Needle', current = context()) {
    return render(
        <LocaleProvider>
            <form role="search">
                <input id="search-logs" defaultValue={value} />
            </form>
            <LogsClearFiltersControl context={current} />
        </LocaleProvider>,
    );
}

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
});

describe('canonical logs copy-visible control', () => {
    it('copies only the authoritative rows reread for the active tenant and current search', async () => {
        const fetchMock = vi.fn().mockResolvedValue(response({
            data: [
                { level: 'Information', message: 'Needle event for Alpha' },
                { level: 'Warning', message: 'Other matching Alpha event' },
            ],
        }));
        vi.stubGlobal('fetch', fetchMock);
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });

        renderControl();

        const copy = screen.getByRole('button', { name: 'Copy results' });
        await waitFor(() => expect(copy).toBeEnabled());
        expect(copy).toHaveAttribute('data-canonical-operation', LOGS_COPY_VISIBLE_OPERATION_ID);
        expect(LOGS_COPY_VISIBLE_OPERATION_ID).toBe('AIMW-BILL-2138CD95B2');

        fireEvent.click(copy);

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(fetchMock.mock.calls[0][0]).toBe('/tenants/alpha/admin/logs?search=Needle');
        await waitFor(() => expect(writeText).toHaveBeenCalledWith(
            '[Information] Needle event for Alpha\n[Warning] Other matching Alpha event',
        ));
        expect(await screen.findByRole('status')).toHaveTextContent('Visible results copied.');
    });

    it('reports clipboard rejection as failure and never reports copy success', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response({
            data: [{ level: 'Error', message: 'Alpha failure' }],
        })));
        const writeText = vi.fn().mockRejectedValue(new Error('clipboard denied'));
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });

        renderControl('');
        const copy = screen.getByRole('button', { name: 'Copy results' });
        await waitFor(() => expect(copy).toBeEnabled());
        fireEvent.click(copy);

        expect(await screen.findByRole('alert')).toHaveTextContent('Visible results could not be copied.');
        expect(screen.queryByText('Visible results copied.')).not.toBeInTheDocument();
    });

    it('fails closed when the context advertises a foreign-tenant logs endpoint or lacks required permission', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: vi.fn() } });

        const { rerender } = renderControl('', context({ api: { logs: '/tenants/beta/admin/logs' } }));
        const foreign = screen.getByRole('button', { name: 'Copy results' });
        await waitFor(() => expect(foreign).toBeDisabled());
        fireEvent.click(foreign);
        expect(fetchMock).not.toHaveBeenCalled();

        rerender(
            <LocaleProvider>
                <form role="search"><input id="search-logs" defaultValue="" /></form>
                <LogsClearFiltersControl context={context({ permissions: ['tenant.view', 'diagnostics.view'] })} />
            </LocaleProvider>,
        );
        expect(screen.getByRole('button', { name: 'Copy results' })).toBeDisabled();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('formats rows using the source [level] message clipboard contract', () => {
        expect(formatVisibleLogs([
            { level: 'Info', message: 'one' },
            { level: 'Critical', message: 'two' },
        ])).toBe('[Info] one\n[Critical] two');
    });
});
