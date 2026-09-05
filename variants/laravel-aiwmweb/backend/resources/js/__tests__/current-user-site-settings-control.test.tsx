import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import {
    CURRENT_USER_SITE_SETTINGS_OPERATION_ID,
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
        api: {
            sites: '/api/tenants/alpha/sites',
            'sites.detail.7': '/api/tenants/alpha/sites/7',
        },
        actions: {},
        active_site: { id: 7, name: 'Alpha Site', status: 'active' },
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

afterEach(() => {
    document.querySelectorAll('.user-chip').forEach((node) => node.remove());
});

describe(`${CURRENT_USER_SITE_SETTINGS_OPERATION_ID} CurrentUserChip site settings`, () => {
    it('renders the exact tenant and active-site settings destination from authoritative context', async () => {
        renderControl(context());

        const link = await screen.findByRole('link', { name: 'Open Alpha Site site settings' });
        expect(link).toHaveAttribute('data-canonical-operation', CURRENT_USER_SITE_SETTINGS_OPERATION_ID);
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites/7/settings');
        expect(link).toHaveTextContent('Settings');
    });

    it('fails closed without an active site, sites.view, or the exact site detail contract', async () => {
        const noActive = renderControl(context({ active_site: null }));
        await waitFor(() => expect(screen.queryByRole('link', { name: /site settings/i })).not.toBeInTheDocument());
        noActive.unmount();
        document.querySelectorAll('.user-chip').forEach((node) => node.remove());

        const noPermission = renderControl(context({ permissions: ['tenant.view'] }));
        await waitFor(() => expect(screen.queryByRole('link', { name: /site settings/i })).not.toBeInTheDocument());
        noPermission.unmount();
        document.querySelectorAll('.user-chip').forEach((node) => node.remove());

        renderControl(context({ api: { sites: '/api/tenants/alpha/sites' } }));
        await waitFor(() => expect(screen.queryByRole('link', { name: /site settings/i })).not.toBeInTheDocument());
    });
});
