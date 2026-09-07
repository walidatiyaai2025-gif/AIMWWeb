import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import {
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
        await waitFor(() => expect(screen.queryByRole('link')).not.toBeInTheDocument());
        first.unmount();
        document.querySelectorAll('.user-chip').forEach((node) => node.remove());

        const second = renderControl(context({ permissions: ['tenant.view'] }));
        await waitFor(() => expect(screen.queryByRole('link')).not.toBeInTheDocument());
        second.unmount();
        document.querySelectorAll('.user-chip').forEach((node) => node.remove());

        renderControl(context({ api: { 'sites.detail.12': '/api/tenants/beta/sites/12' } }));
        await waitFor(() => expect(screen.queryByRole('link')).not.toBeInTheDocument());
    });

    it('rejects invalid active-site identifiers instead of synthesizing a direct-ID route', async () => {
        renderControl(context({ active_site: { id: 0, name: 'Invalid' }, api: {} }));
        await waitFor(() => expect(screen.queryByRole('link')).not.toBeInTheDocument());
    });
});
