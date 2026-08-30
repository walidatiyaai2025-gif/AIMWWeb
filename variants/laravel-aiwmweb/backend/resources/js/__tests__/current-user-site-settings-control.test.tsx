import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CurrentUserSiteDetailsControl } from '../current-user-site-details-control';
import { CURRENT_USER_SITE_SETTINGS_OPERATION_ID } from '../current-user-site-settings-control';
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
        permissions: ['tenant.view', 'sites.view', 'sites.manage'],
        connectors: [],
        capabilities: {},
        api: { 'sites.detail.7': '/api/tenants/alpha/sites/7' },
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
    vi.unstubAllGlobals();
});

describe(`${CURRENT_USER_SITE_SETTINGS_OPERATION_ID} CurrentUserChip site-settings navigation`, () => {
    it('wires the canonical settings control through the real CurrentUserChip integration surface', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        renderControl(context());

        const link = await screen.findByRole('link', { name: 'Open Alpha Site site settings' });
        expect(link).toHaveAttribute('data-canonical-operation', CURRENT_USER_SITE_SETTINGS_OPERATION_ID);
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites/7/settings');
        expect(screen.getByRole('link', { name: 'Open Alpha Site site details' })).toHaveAttribute('href', '/tenants/alpha/sites/7');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('derives the destination only from authoritative tenant and active-site context', async () => {
        renderControl(context({ tenant: { slug: 'authoritative', name: 'Authoritative' }, api: { 'sites.detail.7': '/api/tenants/authoritative/sites/7' } }));

        const link = await screen.findByRole('link', { name: 'Open Alpha Site site settings' });
        expect(link).toHaveAttribute('href', '/tenants/authoritative/sites/7/settings');
    });

    it('fails closed without sites.manage, an active site, a safe site id, or the exact tenant site contract', async () => {
        const cases: ContextWithActiveSite[] = [
            context({ permissions: ['tenant.view', 'sites.view'] }),
            context({ active_site: null }),
            context({ active_site: { id: 0, name: 'Unsafe' } }),
            context({ api: { 'sites.detail.7': '/api/tenants/beta/sites/7' } }),
            context({ api: {} }),
        ];

        for (const value of cases) {
            const rendered = renderControl(value);
            await waitFor(() => expect(screen.queryByRole('link', { name: /site settings/i })).not.toBeInTheDocument());
            rendered.unmount();
            document.querySelectorAll('.user-chip').forEach((node) => node.remove());
        }
    });
});
