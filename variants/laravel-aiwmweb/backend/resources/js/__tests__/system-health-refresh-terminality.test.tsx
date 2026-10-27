import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SYSTEM_HEALTH_REFRESH_OPERATION_ID,
    SystemHealthRefreshControl,
} from '../system-health-refresh-control';

function context(permissions = ['tenant.view', 'diagnostics.view']): FrontendContext {
    return {
        user: { id: 1, name: 'Alpha Operator', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions,
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
    };
}

function renderControl(onRefresh: () => Promise<unknown>, value = context(), refreshing = false) {
    return render(
        <LocaleProvider>
            <SystemHealthRefreshControl context={value} onRefresh={onRefresh} refreshing={refreshing} />
        </LocaleProvider>,
    );
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe(`${SYSTEM_HEALTH_REFRESH_OPERATION_ID} RefreshClickedAsync`, () => {
    it('marks the real refresh action and prevents duplicate clicks while the authoritative read is pending', async () => {
        let release!: () => void;
        const pending = new Promise<void>((resolve) => { release = resolve; });
        const onRefresh = vi.fn(() => pending);

        renderControl(onRefresh);

        const button = screen.getByRole('button', { name: 'Run checks' });
        expect(button).toHaveAttribute('data-canonical-operation', SYSTEM_HEALTH_REFRESH_OPERATION_ID);

        fireEvent.click(button);
        fireEvent.click(button);

        expect(onRefresh).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('button', { name: 'Checking...' })).toBeDisabled();

        release();
        await waitFor(() => expect(screen.getByRole('button', { name: 'Run checks' })).toBeEnabled());
    });

    it('is disabled while the parent query is already fetching', () => {
        renderControl(vi.fn(async () => undefined), context(), true);

        expect(screen.getByRole('button', { name: 'Checking...' })).toBeDisabled();
    });

    it('fails closed when tenant identity or diagnostics permission is not authoritative', () => {
        const onRefresh = vi.fn(async () => undefined);
        const { rerender } = renderControl(onRefresh, context(['tenant.view']));

        expect(screen.queryByRole('button', { name: /checks/i })).not.toBeInTheDocument();

        const foreignContext = context();
        foreignContext.tenants = [{ slug: 'beta', name: 'Beta' }];
        rerender(
            <LocaleProvider>
                <SystemHealthRefreshControl context={foreignContext} onRefresh={onRefresh} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: /checks/i })).not.toBeInTheDocument();
        expect(onRefresh).not.toHaveBeenCalled();
    });

    it('does not surface synthetic success when the authoritative refresh rejects', async () => {
        const onRefresh = vi.fn(async () => { throw new Error('database unavailable'); });
        renderControl(onRefresh);

        fireEvent.click(screen.getByRole('button', { name: 'Run checks' }));

        await waitFor(() => expect(screen.getByRole('button', { name: 'Run checks' })).toBeEnabled());
        expect(screen.queryByText(/success/i)).not.toBeInTheDocument();
        expect(onRefresh).toHaveBeenCalledTimes(1);
    });
});
