import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    GLOBAL_SYNCHRONIZATION_CONNECT_SITE_OPERATION_ID,
    GlobalSynchronizationConnectSiteLinkControl,
    globalSynchronizationConnectSiteDestination,
} from '../global-synchronization-connect-site-link-control';

type Context = FrontendContext & { active_site?: { id: number; name: string } | null };

function context(): Context {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'content.view', 'sync.view', 'sites.manage'],
        connectors: [],
        capabilities: {},
        api: { sites: '/api/tenants/alpha/sites' },
        actions: {},
        active_site: null,
    };
}

function display(value: Context) {
    return render(
        <MemoryRouter>
            <LocaleProvider>
                <GlobalSynchronizationConnectSiteLinkControl context={value} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

afterEach(() => { cleanup(); window.localStorage.clear(); });

describe('AIMW-SYNC-CC2B584BC4 Global Synchronization connect-site link', () => {
    it('uses the canonical operation and real onboarding route in the no-selection state', () => {
        expect(GLOBAL_SYNCHRONIZATION_CONNECT_SITE_OPERATION_ID).toBe('AIMW-SYNC-CC2B584BC4');
        expect(globalSynchronizationConnectSiteDestination(context())).toBe('/tenants/alpha/sites/connect');
        display(context());
        const link = screen.getByRole('link', { name: 'Connect a site' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites/connect');
        expect(link).toHaveAttribute('data-canonical-operation', GLOBAL_SYNCHRONIZATION_CONNECT_SITE_OPERATION_ID);
    });

    it('hides the empty-state action when a site is already selected', () => {
        const value = context(); value.active_site = { id: 7, name: 'Selected' };
        expect(globalSynchronizationConnectSiteDestination(value)).toBeNull();
        display(value);
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it.each(['tenant.view', 'content.view', 'sync.view', 'sites.manage'])(
        'does not offer the protected link without %s', (permission) => {
            const value = context(); value.permissions = value.permissions.filter((name) => name !== permission);
            expect(globalSynchronizationConnectSiteDestination(value)).toBeNull();
            display(value);
            expect(screen.queryByRole('link')).not.toBeInTheDocument();
        },
    );

    it('rejects forged cross-tenant bindings and disabled site management', () => {
        const badTenant = context(); badTenant.tenant.slug = 'alpha/../beta';
        expect(globalSynchronizationConnectSiteDestination(badTenant)).toBeNull();
        const foreignBinding = context(); foreignBinding.api.sites = '/api/tenants/beta/sites';
        expect(globalSynchronizationConnectSiteDestination(foreignBinding)).toBeNull();
        const missingMembership = context(); missingMembership.tenants = [];
        expect(globalSynchronizationConnectSiteDestination(missingMembership)).toBeNull();
        const disabled = context(); disabled.capabilities = { 'site-connect': { state: 'disabled_by_owner' } };
        expect(globalSynchronizationConnectSiteDestination(disabled)).toBeNull();
    });

    it('does not require an already paired WordPress connector to navigate to first-time setup', () => {
        expect(context().connectors).toHaveLength(0);
        expect(globalSynchronizationConnectSiteDestination(context())).toBe('/tenants/alpha/sites/connect');
    });

    it('keeps source Arabic labeling and authoritative tenant destination', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        display(context());
        expect(screen.getByRole('link', { name: 'إضافة موقع' }))
            .toHaveAttribute('href', '/tenants/alpha/sites/connect');
    });
});
