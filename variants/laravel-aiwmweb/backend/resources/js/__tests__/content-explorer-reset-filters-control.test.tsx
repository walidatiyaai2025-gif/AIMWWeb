import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import {
    authoritativeContentExplorerResetEndpoint,
    CONTENT_EXPLORER_RESET_FILTERS_OPERATION_ID,
    ContentExplorerResetFiltersControl,
} from '../content-explorer-reset-filters-control';
import { LocaleProvider } from '../i18n';

type SiteAwareContext = FrontendContext & { active_site?: { id: number; name: string } | null };

function context(overrides: Partial<SiteAwareContext> = {}): SiteAwareContext {
    return {
        user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view'],
        connectors: [],
        capabilities: {},
        api: { explorer: '/api/v1/tenants/alpha/sites/7/explorer' },
        actions: {},
        active_site: { id: 7, name: 'Alpha Site' },
        ...overrides,
    };
}

function display(value = context(), onReset = vi.fn().mockResolvedValue(undefined), busy = false) {
    return render(
        <LocaleProvider>
            <ContentExplorerResetFiltersControl context={value} busy={busy} onResetRequested={onReset} />
        </LocaleProvider>,
    );
}

afterEach(() => cleanup());

describe('AIMW-SYNC-627C90DC5C ContentExplorer ResetFiltersAsync', () => {
    it('uses the exact site/tenant issued endpoint and delegates a real refetch', async () => {
        expect(CONTENT_EXPLORER_RESET_FILTERS_OPERATION_ID).toBe('AIMW-SYNC-627C90DC5C');
        const reset = vi.fn().mockResolvedValue(undefined);
        display(context(), reset);

        const control = screen.getByRole('button', { name: 'Reset filters' });
        expect(control).toHaveAttribute('data-canonical-operation', CONTENT_EXPLORER_RESET_FILTERS_OPERATION_ID);
        fireEvent.click(control);
        await waitFor(() => expect(reset).toHaveBeenCalledTimes(1));
        expect(reset).toHaveBeenCalledWith('/api/v1/tenants/alpha/sites/7/explorer');
    });

    it('fails closed on foreign tenant, foreign site, untrusted origins, and missing permissions', () => {
        expect(authoritativeContentExplorerResetEndpoint(context({
            api: { explorer: '/api/v1/tenants/beta/sites/7/explorer' },
        }))).toBeNull();
        expect(authoritativeContentExplorerResetEndpoint(context({
            api: { explorer: '/api/v1/tenants/alpha/sites/9/explorer' },
        }))).toBeNull();
        expect(authoritativeContentExplorerResetEndpoint(context({
            api: { explorer: 'https://remote.example.test/api/v1/tenants/alpha/sites/7/explorer' },
        }))).toBeNull();
        expect(authoritativeContentExplorerResetEndpoint(context({
            api: { explorer: '/api/v1/tenants/alpha/sites/7/explorer?site=9' },
        }))).toBeNull();
        expect(authoritativeContentExplorerResetEndpoint(context({
            permissions: ['tenant.view'],
        }))).toBeNull();
        expect(authoritativeContentExplorerResetEndpoint(context({
            active_site: null,
        }))).toBeNull();
        const { container } = display(context({ permissions: ['tenant.view'] }));
        expect(container).toBeEmptyDOMElement();
    });

    it('disables reset while the real Explorer query is in flight', () => {
        display(context(), vi.fn(), true);
        expect(screen.getByRole('button', { name: 'Reset filters' })).toBeDisabled();
    });

    it('shows failure instead of claiming reset success', async () => {
        const reset = vi.fn().mockRejectedValue(new Error('Server unavailable'));
        display(context(), reset);
        fireEvent.click(screen.getByRole('button', { name: 'Reset filters' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Server unavailable');
        expect(screen.queryByText(/reset complete/i)).toBeNull();
    });
});
