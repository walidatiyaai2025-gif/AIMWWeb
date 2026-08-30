import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { LogsCloseDetailsControl, LOGS_CLOSE_DETAILS_OPERATION_ID } from '../logs-close-details-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

function context(overrides: Partial<FrontendContext> = {}): FrontendContext {
    return {
        user: { id: 1, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'operations.manage', 'diagnostics.view'],
        connectors: [],
        capabilities: {},
        api: { logs: '/tenants/alpha/admin/logs' },
        actions: {},
        ...overrides,
    };
}

function renderControl(value = context()) {
    return render(
        <LocaleProvider>
            <LogsCloseDetailsControl context={value} />
        </LocaleProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe(`${LOGS_CLOSE_DETAILS_OPERATION_ID} Logs CloseDetails`, () => {
    it('closes the selected authoritative log detail locally without a second request or route mutation', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            data: [{ id: 17, level: 'Error', message: 'Database timeout', correlation_id: 'corr-17' }],
        }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();
        fireEvent.click(await screen.findByRole('button', { name: 'Inspect log detail 17' }));

        expect(screen.getByRole('region', { name: 'Line details' })).toHaveTextContent('Database timeout');
        const close = screen.getByRole('button', { name: 'Close log details' });
        expect(close).toHaveAttribute('data-canonical-operation', LOGS_CLOSE_DETAILS_OPERATION_ID);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/tenants/alpha/admin/logs');

        fireEvent.click(close);
        await waitFor(() => expect(screen.queryByRole('region', { name: 'Line details' })).not.toBeInTheDocument());
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('fails closed without both diagnostics and operations read authority or with a mismatched endpoint', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        const missingPermission = renderControl(context({ permissions: ['tenant.view', 'diagnostics.view'] }));
        await waitFor(() => expect(screen.queryByLabelText('Log details')).not.toBeInTheDocument());
        expect(fetchMock).not.toHaveBeenCalled();
        missingPermission.unmount();

        renderControl(context({ api: { logs: '/tenants/beta/admin/logs' } }));
        await waitFor(() => expect(screen.queryByLabelText('Log details')).not.toBeInTheDocument());
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('does not fabricate a close surface when the authoritative read fails or is malformed', async () => {
        const failedFetch = vi.fn().mockRejectedValue(new Error('logs unavailable'));
        vi.stubGlobal('fetch', failedFetch);
        const failed = renderControl();
        await waitFor(() => expect(failedFetch).toHaveBeenCalledTimes(1));
        expect(screen.queryByRole('button', { name: 'Close log details' })).not.toBeInTheDocument();
        failed.unmount();

        const malformedFetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { unexpected: true } }), {
            status: 200,
            headers: { 'content-type': 'application/json' },
        }));
        vi.stubGlobal('fetch', malformedFetch);
        renderControl();
        await waitFor(() => expect(malformedFetch).toHaveBeenCalledTimes(1));
        expect(screen.queryByRole('button', { name: 'Close log details' })).not.toBeInTheDocument();
    });
});
