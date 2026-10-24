import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    canSelectAuthoritativeContentExplorerTab,
    CONTENT_EXPLORER_CONTENT_TAB_OPERATION_ID,
    CONTENT_EXPLORER_CONTENT_TAB_RESET_EVENT,
    ContentExplorerContentTabControl,
} from '../content-explorer-content-tab-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

type SiteAwareContext = FrontendContext & {
    active_site?: { id: number; name: string; status: string } | null;
};

function context(overrides: Partial<SiteAwareContext> = {}): SiteAwareContext {
    return {
        user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view'],
        connectors: [],
        capabilities: {},
        api: { explorer: '/api/v1/tenants/alpha/sites/17/explorer' },
        actions: {},
        active_site: { id: 17, name: 'Alpha Site', status: 'active' },
        ...overrides,
    };
}

function renderControl(value: FrontendContext) {
    return render(
        <LocaleProvider>
            <ContentExplorerContentTabControl context={value} />
        </LocaleProvider>,
    );
}

describe('AIMW-CONT-93B6E02ACF ContentExplorer SetContentTab', () => {
    it('renders the active authoritative Content tab and broadcasts the local reset semantic', () => {
        const reset = vi.fn();
        window.addEventListener(CONTENT_EXPLORER_CONTENT_TAB_RESET_EVENT, reset);

        renderControl(context());
        const button = screen.getByRole('button', { name: /Content/ });
        expect(button).toHaveAttribute('aria-pressed', 'true');
        expect(button).toHaveAttribute('data-canonical-operation', CONTENT_EXPLORER_CONTENT_TAB_OPERATION_ID);
        expect(CONTENT_EXPLORER_CONTENT_TAB_OPERATION_ID).toBe('AIMW-CONT-93B6E02ACF');

        fireEvent.click(button);
        expect(reset).toHaveBeenCalledTimes(1);
        window.removeEventListener(CONTENT_EXPLORER_CONTENT_TAB_RESET_EVENT, reset);
    });

    it('fails closed when the active site or exact tenant/site Explorer authority does not match', () => {
        expect(canSelectAuthoritativeContentExplorerTab(context({ active_site: null }))).toBe(false);
        expect(canSelectAuthoritativeContentExplorerTab(context({ active_site: { id: -1, name: 'Invalid', status: 'active' } }))).toBe(false);
        expect(canSelectAuthoritativeContentExplorerTab(context({ api: {} }))).toBe(false);
        expect(canSelectAuthoritativeContentExplorerTab(context({ api: { explorer: '/api/v1/tenants/alpha/sites/99/explorer' } }))).toBe(false);
        expect(canSelectAuthoritativeContentExplorerTab(context({ api: { explorer: '/api/v1/tenants/beta/sites/17/explorer' } }))).toBe(false);
        expect(canSelectAuthoritativeContentExplorerTab(context({ api: { explorer: 'https://foreign.example.test/api/v1/tenants/alpha/sites/17/explorer' } }))).toBe(false);
        expect(canSelectAuthoritativeContentExplorerTab(context({ api: { explorer: '/api/v1/tenants/alpha/sites/17/explorer?site=99' } }))).toBe(false);
    });

    it.each([
        ['tenant.view is absent', ['sites.view']],
        ['sites.view is absent', ['tenant.view']],
    ])('does not render when %s', (_label, permissions) => {
        renderControl(context({ permissions }));
        expect(screen.queryByRole('button', { name: /Content/ })).not.toBeInTheDocument();
    });
});

// exact-head gate retrigger after parity materialization
