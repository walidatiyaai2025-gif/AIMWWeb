import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import {
    authoritativeCurrentUserContentExplorerHref,
    CURRENT_USER_CONTENT_EXPLORER_OPERATION_ID,
    CURRENT_USER_SITE_DETAILS_OPERATION_ID,
    CurrentUserSiteDetailsControl,
} from '../current-user-site-details-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

type ContextWithActiveSite = FrontendContext & {
    active_site?: { id: number; name: string; status?: string | null } | null;
};

function context(overrides: Partial<ContextWithActiveSite> = {}): ContextWithActiveSite {
    return {
        user: { id: 1, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view'],
        connectors: [],
        capabilities: {},
        api: { 'sites.detail.12': '/api/tenants/alpha/sites/12' },
        actions: {},
        active_site: { id: 12, name: 'Alpha Site', status: 'active' },
        ...overrides,
    };
}

function renderControl(value: ContextWithActiveSite) {
    const host = document.createElement('div');
    host.className = 'user-chip';
    document.body.appendChild(host);

    return render(
        <LocaleProvider>
            <CurrentUserSiteDetailsControl context={value} />
        </LocaleProvider>,
    );
}

function querySiteDetailsLink() {
    return screen.queryByRole('link', { name: /site details$/i });
}

function queryContentLink() {
    return screen.queryByRole('link', { name: /content$/i });
}

afterEach(() => {
    document.querySelectorAll('.user-chip').forEach((node) => node.remove());
});

describe(`${CURRENT_USER_SITE_DETAILS_OPERATION_ID} CurrentUserChip site details`, () => {
    it('renders the exact active tenant/site destination from the authoritative context contract without a request', async () => {
        renderControl(context());

        const link = await screen.findByRole('link', { name: 'Open Alpha Site site details' });
        expect(link).toHaveAttribute('data-canonical-operation', CURRENT_USER_SITE_DETAILS_OPERATION_ID);
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites/12');
        expect(link).toHaveTextContent('Alpha Site');
    });

    it('fails closed when active site, permission, or exact advertised detail API binding is absent', async () => {
        const first = renderControl(context({ active_site: null }));
        await waitFor(() => expect(querySiteDetailsLink()).not.toBeInTheDocument());
        first.unmount();
        document.querySelectorAll('.user-chip').forEach((node) => node.remove());

        const second = renderControl(context({ permissions: ['tenant.view'] }));
        await waitFor(() => expect(querySiteDetailsLink()).not.toBeInTheDocument());
        second.unmount();
        document.querySelectorAll('.user-chip').forEach((node) => node.remove());

        renderControl(context({ api: { 'sites.detail.12': '/api/tenants/beta/sites/12' } }));
        await waitFor(() => expect(querySiteDetailsLink()).not.toBeInTheDocument());
    });

    it('rejects invalid active-site identifiers instead of synthesizing a direct-ID route', async () => {
        renderControl(context({ active_site: { id: 0, name: 'Invalid' }, api: {} }));
        await waitFor(() => expect(querySiteDetailsLink()).not.toBeInTheDocument());
    });
});

describe(`${CURRENT_USER_CONTENT_EXPLORER_OPERATION_ID} CurrentUserChip Content navigation`, () => {
    it('preserves the source active-site Content action with the Laravel tenant-qualified Explorer destination', async () => {
        renderControl(context());

        const link = await screen.findByRole('link', { name: 'Open Alpha Site content' });
        expect(link).toHaveAttribute('data-canonical-operation', CURRENT_USER_CONTENT_EXPLORER_OPERATION_ID);
        expect(link).toHaveAttribute('href', '/tenants/alpha/explorer?site=12');
        expect(link).toHaveTextContent('Content');
        expect(authoritativeCurrentUserContentExplorerHref(context())).toBe('/tenants/alpha/explorer?site=12');
    });

    it('does not invent a ContentView requirement that the source CurrentUserChip does not have', async () => {
        renderControl(context({ permissions: ['tenant.view', 'sites.view'] }));
        expect(await screen.findByRole('link', { name: 'Open Alpha Site content' })).toBeInTheDocument();
    });

    it.each([
        ['active site is absent', { active_site: null }],
        ['active site is invalid', { active_site: { id: 0, name: 'Invalid' }, api: {} }],
        ['tenant.view is absent', { permissions: ['sites.view'] }],
        ['sites.view is absent', { permissions: ['tenant.view'] }],
        ['site API binding is absent', { api: {} }],
        ['site API binding points at another tenant', { api: { 'sites.detail.12': '/api/tenants/beta/sites/12' } }],
        ['site API binding points at another site', { api: { 'sites.detail.12': '/api/tenants/alpha/sites/99' } }],
        ['Explorer is disabled by owner', { capabilities: { explorer: { state: 'disabled_by_owner' as const } } }],
    ])('fails closed when %s', async (_label, overrides) => {
        renderControl(context(overrides as Partial<ContextWithActiveSite>));
        await waitFor(() => expect(queryContentLink()).not.toBeInTheDocument());
    });

    it('encodes the server-provided tenant slug instead of permitting a tenant path escape', () => {
        const value = context({
            tenant: { slug: 'alpha/../beta', name: 'Alpha' },
            api: { 'sites.detail.12': '/api/tenants/alpha/../beta/sites/12' },
        });

        expect(authoritativeCurrentUserContentExplorerHref(value)).toBe('/tenants/alpha%2F..%2Fbeta/explorer?site=12');
        expect(authoritativeCurrentUserContentExplorerHref(value)).not.toContain('/tenants/beta/');
    });
});
