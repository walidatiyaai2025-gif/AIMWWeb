import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    DashboardErrorRetryControl,
    DASHBOARD_ERROR_RETRY_OPERATION_ID,
} from '../dashboard-error-retry-control';

const endpoint = '/api/dashboard?tenant=alpha';

function context(tenantSlug = 'alpha', memberships: string[] = ['alpha'], dashboardApi = endpoint): FrontendContext {
    return {
        user: { id: 10, name: 'Operator', email: 'operator@example.test' },
        tenant: { slug: tenantSlug, name: 'Alpha' },
        tenants: memberships.map((slug) => ({ slug, name: slug })),
        permissions: ['tenant.view', 'execution.view'],
        connectors: [],
        capabilities: {},
        api: { dashboard: dashboardApi },
        actions: {},
    };
}

function renderControl(onRetry: () => Promise<unknown>, value = context(), apiEndpoint = endpoint) {
    return render(
        <LocaleProvider>
            <DashboardErrorRetryControl context={value} endpoint={apiEndpoint} onRetry={onRetry} />
        </LocaleProvider>,
    );
}

afterEach(() => cleanup());

describe('AIMW-SYNC-B11D963A9E dashboard error-state Try again', () => {
    it('binds the exact operation marker to one authoritative reread callback', async () => {
        const onRetry = vi.fn(async () => ({ data: { sites: { totalSites: 1 } } }));
        renderControl(onRetry);

        const button = screen.getByRole('button', { name: 'Try again' });
        expect(DASHBOARD_ERROR_RETRY_OPERATION_ID).toBe('AIMW-SYNC-B11D963A9E');
        expect(button).toHaveAttribute('data-canonical-operation', DASHBOARD_ERROR_RETRY_OPERATION_ID);
        expect(button).toHaveAttribute('type', 'button');

        fireEvent.click(button);
        await waitFor(() => expect(onRetry).toHaveBeenCalledTimes(1));
        await waitFor(() => expect(button).toHaveAttribute('aria-busy', 'false'));
    });

    it('sends no duplicate rereads while the original request is outstanding', async () => {
        let release!: () => void;
        const onRetry = vi.fn(() => new Promise<void>((resolve) => { release = resolve; }));
        renderControl(onRetry);
        const button = screen.getByRole('button', { name: 'Try again' });

        fireEvent.click(button);
        expect(button).toBeDisabled();
        expect(button).toHaveAttribute('aria-busy', 'true');
        fireEvent.click(button);
        expect(onRetry).toHaveBeenCalledTimes(1);

        release();
        await waitFor(() => expect(button).not.toBeDisabled());
    });

    it('does not manufacture success and allows retry after authoritative failure', async () => {
        const onRetry = vi.fn().mockRejectedValueOnce(new Error('service unavailable')).mockResolvedValueOnce({});
        renderControl(onRetry);
        const button = screen.getByRole('button', { name: 'Try again' });

        fireEvent.click(button);
        await waitFor(() => expect(button).not.toBeDisabled());
        expect(onRetry).toHaveBeenCalledTimes(1);

        fireEvent.click(button);
        await waitFor(() => expect(onRetry).toHaveBeenCalledTimes(2));
    });

    it('fails closed without an active server membership or a matching server-advertised API contract', () => {
        const onRetry = vi.fn(async () => ({}));
        const result = renderControl(onRetry, context('alpha', ['beta']));
        expect(result.container).toBeEmptyDOMElement();
        result.rerender(
            <LocaleProvider>
                <DashboardErrorRetryControl
                    context={context()}
                    endpoint="/api/dashboard?tenant=beta"
                    onRetry={onRetry}
                />
            </LocaleProvider>,
        );
        expect(result.container).toBeEmptyDOMElement();
        expect(onRetry).not.toHaveBeenCalled();
    });
});
