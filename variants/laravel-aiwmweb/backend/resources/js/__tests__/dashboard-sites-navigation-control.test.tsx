import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import {
    DASHBOARD_SITES_NAVIGATION_OPERATION_ID,
    DashboardSitesNavigationControl,
} from '../dashboard-sites-navigation-control';
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
            <DashboardSitesNavigationControl context={value} />
        </LocaleProvider>,
    );
}

afterEach(() => cleanup());

describe('AIMW-CONT-71F54E3BA6 dashboard /sites navigation', () => {
    it('uses the authoritative active tenant and exact canonical operation marker', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: 'Add site' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites');
        expect(link).toHaveAttribute('data-canonical-operation', DASHBOARD_SITES_NAVIGATION_OPERATION_ID);
        expect(DASHBOARD_SITES_NAVIGATION_OPERATION_ID).toBe('AIMW-CONT-71F54E3BA6');
        expect(link).not.toHaveAttribute('data-method');
    });

    it('fails closed when sites.view is absent', () => {
        renderControl(context('alpha', ['tenant.view']));
        expect(screen.queryByRole('link', { name: 'Add site' })).not.toBeInTheDocument();
    });

    it('fails closed when the active tenant is not in the server-provided membership set', () => {
        renderControl(context('alpha', undefined, ['beta']));
        expect(screen.queryByRole('link', { name: 'Add site' })).not.toBeInTheDocument();
    });

    it('rejects a foreign Sites API contract', () => {
        renderControl(context('alpha', undefined, ['alpha'], '/api/tenants/beta/sites'));
        expect(screen.queryByRole('link', { name: 'Add site' })).not.toBeInTheDocument();
    });

    it('URL-encodes the server-derived tenant slug', () => {
        renderControl(context('alpha/../beta'));
        const link = screen.getByRole('link', { name: 'Add site' });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/sites');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/sites');
    });
});
