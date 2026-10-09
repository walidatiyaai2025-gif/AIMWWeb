import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    LogsCloseDetailsControl,
    LOGS_COPY_SELECTED_OPERATION_ID,
    extractSelectedLogErrorCode,
    formatSelectedLog,
} from '../logs-close-details-control';
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
    return render(<LocaleProvider><LogsCloseDetailsControl context={value} /></LocaleProvider>);
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe(`${LOGS_COPY_SELECTED_OPERATION_ID} Logs CopySelectedAsync`, () => {
    it('preserves source formatting and error-code extraction', () => {
        expect(extractSelectedLogErrorCode('failed ERR-5000 now')).toBe('ERR-5000');
        expect(formatSelectedLog({
            id: 17,
            file: 'app.log',
            level: 'Error',
            message: 'failed ERR-5000 now',
        })).toBe('File: app.log\nLine: 17\nLevel: Error\nError code: ERR-5000\n\nfailed ERR-5000 now');
    });

    it('copies only the selected authoritative tenant row and reports success after clipboard confirmation', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            data: [{ id: 17, file: 'app.log', level: 'Error', message: 'Database ERR-5001 timeout' }],
        }), { status: 200, headers: { 'content-type': 'application/json' } }));
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('fetch', fetchMock);
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });

        renderControl();
        fireEvent.click(await screen.findByRole('button', { name: 'Inspect log detail 17' }));
        const copy = screen.getByRole('button', { name: 'Copy error details' });
        expect(copy).toHaveAttribute('data-canonical-operation', LOGS_COPY_SELECTED_OPERATION_ID);

        fireEvent.click(copy);
        await waitFor(() => expect(writeText).toHaveBeenCalledWith(
            'File: app.log\nLine: 17\nLevel: Error\nError code: ERR-5001\n\nDatabase ERR-5001 timeout',
        ));
        expect(await screen.findByRole('status')).toHaveTextContent('Error details copied.');
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('fails closed for a foreign advertised logs endpoint and never reports false clipboard success', async () => {
        const fetchMock = vi.fn();
        const writeText = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });

        renderControl(context({ api: { logs: '/tenants/beta/admin/logs' } }));
        await waitFor(() => expect(screen.queryByLabelText('Log details')).not.toBeInTheDocument());
        expect(fetchMock).not.toHaveBeenCalled();
        expect(writeText).not.toHaveBeenCalled();
    });
});
