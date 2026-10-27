import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import {
    DASHBOARD_SITES_QUICK_ACCESS_OPERATION_ID,
    DashboardSitesQuickAccessControl,
} from '../dashboard-sites-quick-access-control';
import { LocaleProvider } from '../i18n';

const context = (
    slug = 'alpha',
    permissions: string[] = ['tenant.view', 'sites.view'],
    tenantSlugs: string[] = [slug],
    sitesApi = `/api/tenants/${encodeURIComponent(slug)}/sites`,
): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: tenantSlugs.map((tenantSlug) => ({ slug: tenantSlug, name: tenantSlug })),
    permissions,
    connectors: [],
    capabilities: {},
    api: { sites: sitesApi },
    actions: {},
});

function renderControl(value: FrontendContext) {
    return render(
        <LocaleProvider>
            <DashboardSitesQuickAccessControl context={value} />
        </LocaleProvider>,
    );
}

afterEach(() => cleanup());

describe('AIMW-CONT-7957ECC794 dashboard quick-access Sites link', () => {
    it('renders the exact tenant-scoped Sites quick-access destination', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: 'Sites' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites');
        expect(link).toHaveAttribute('data-source-control', 'dashboard-quick-access-sites');
        expect(link).toHaveAttribute('data-canonical-operation', DASHBOARD_SITES_QUICK_ACCESS_OPERATION_ID);
        expect(DASHBOARD_SITES_QUICK_ACCESS_OPERATION_ID).toBe('AIMW-CONT-7957ECC794');
        expect(link).not.toHaveAttribute('data-method');
    });

    it('fails closed when sites.view is absent', () => {
        renderControl(context('alpha', ['tenant.view']));
        expect(screen.queryByRole('link', { name: 'Sites' })).not.toBeInTheDocument();
    });

    it('fails closed when the active tenant is absent from server-provided memberships', () => {
        renderControl(context('alpha', undefined, ['beta']));
        expect(screen.queryByRole('link', { name: 'Sites' })).not.toBeInTheDocument();
    });

    it('rejects a foreign Sites API contract', () => {
        renderControl(context('alpha', undefined, ['alpha'], '/api/tenants/beta/sites'));
        expect(screen.queryByRole('link', { name: 'Sites' })).not.toBeInTheDocument();
    });

    it('URL-encodes only the authoritative server tenant slug', () => {
        renderControl(context('alpha/../beta'));
        const link = screen.getByRole('link', { name: 'Sites' });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/sites');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/sites');
    });
});
