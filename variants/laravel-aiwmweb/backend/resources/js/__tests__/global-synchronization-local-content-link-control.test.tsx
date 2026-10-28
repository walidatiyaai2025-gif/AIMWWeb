import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    GLOBAL_SYNCHRONIZATION_LOCAL_CONTENT_OPERATION_ID,
    GlobalSynchronizationLocalContentLinkControl,
    globalSynchronizationLocalContentDestination,
} from '../global-synchronization-local-content-link-control';

type ActiveContext = FrontendContext & { active_site?: { id: number; name: string } | null };

function context(
    activeSite: ActiveContext['active_site'] = { id: 7, name: 'Alpha Site' },
    permissions = ['tenant.view', 'sync.view', 'sites.view'],
    slug = 'alpha',
): ActiveContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug, name: 'Alpha' },
        tenants: [{ slug, name: 'Alpha' }],
        permissions,
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
        active_site: activeSite,
    };
}

function renderControl(value: ActiveContext) {
    return render(
        <MemoryRouter>
            <LocaleProvider>
                <GlobalSynchronizationLocalContentLinkControl context={value} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

afterEach(() => { cleanup(); window.localStorage.clear(); });

describe('AIMW-SYNC-E51F091C0B synchronization local content navigation', () => {
    it('opens the real tenant-scoped explorer for the server-selected site', () => {
        renderControl(context());
        const link = screen.getByRole('link', { name: 'Open local content' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/explorer?site=7');
        expect(link.closest('nav')).toHaveAttribute(
            'data-canonical-operation', GLOBAL_SYNCHRONIZATION_LOCAL_CONTENT_OPERATION_ID,
        );
    });

    it.each([undefined, null, { id: 0, name: 'Invalid' }, { id: -4, name: 'Invalid' }, { id: 1.5, name: 'Invalid' }])(
        'does not expose a destination without a valid server-selected site: %s',
        (site) => {
            renderControl(context(site));
            expect(screen.queryByRole('link')).not.toBeInTheDocument();
        },
    );

    it.each([
        ['tenant.view', ['sync.view', 'sites.view']],
        ['sync.view', ['tenant.view', 'sites.view']],
        ['sites.view', ['tenant.view', 'sync.view']],
    ])('fails closed before the protected endpoint would return 403 without %s', (_permission, permissions) => {
        renderControl(context({ id: 7, name: 'Alpha Site' }, permissions));
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('respects server-declared disabled capability rather than presenting a fake navigation', () => {
        const value = context();
        value.capabilities = { explorer: { state: 'disabled_by_owner' } };
        renderControl(value);
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('encodes tenant identity; a foreign tenant cannot be selected through a path escape (server 404)', () => {
        const value = context({ id: 7, name: 'Alpha Site' }, undefined, 'alpha/../foreign');
        expect(globalSynchronizationLocalContentDestination(value))
            .toBe('/tenants/alpha%2F..%2Fforeign/explorer?site=7');
        renderControl(value);
        expect(screen.getByRole('link', { name: 'Open local content' }))
            .not.toHaveAttribute('href', '/tenants/foreign/explorer?site=7');
    });

    it('renders the Arabic source label on the same trusted destination', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        renderControl(context());
        expect(screen.getByRole('link', { name: 'فتح المحتوى المحلي' }))
            .toHaveAttribute('href', '/tenants/alpha/explorer?site=7');
    });
});
