import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import {
    DASHBOARD_SYSTEM_HEALTH_NAVIGATION_OPERATION_ID,
    DashboardSystemHealthNavigationControl,
} from '../dashboard-system-health-navigation-control';
import { LocaleProvider } from '../i18n';

const context = (
    slug = 'alpha',
    permissions: string[] = ['tenant.view', 'diagnostics.view'],
    tenantSlugs: string[] = [slug],
): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: tenantSlugs.map((tenantSlug) => ({ slug: tenantSlug, name: tenantSlug })),
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

function renderControl(value: FrontendContext) {
    return render(
        <LocaleProvider>
            <DashboardSystemHealthNavigationControl context={value} />
        </LocaleProvider>,
    );
}

afterEach(() => cleanup());

describe('AIMW-CONT-A3A2CD23B6 dashboard /system-health navigation', () => {
    it('uses the authoritative active tenant and exact canonical operation marker', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: 'System health' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/system-health');
        expect(link).toHaveAttribute(
            'data-canonical-operation',
            DASHBOARD_SYSTEM_HEALTH_NAVIGATION_OPERATION_ID,
        );
        expect(DASHBOARD_SYSTEM_HEALTH_NAVIGATION_OPERATION_ID).toBe('AIMW-CONT-A3A2CD23B6');
        expect(link).not.toHaveAttribute('data-method');
    });

    it('fails closed when diagnostics.view is absent', () => {
        renderControl(context('alpha', ['tenant.view']));
        expect(screen.queryByRole('link', { name: 'System health' })).not.toBeInTheDocument();
    });

    it('fails closed when the active tenant is not in the server-provided membership set', () => {
        renderControl(context('alpha', undefined, ['beta']));
        expect(screen.queryByRole('link', { name: 'System health' })).not.toBeInTheDocument();
    });

    it('URL-encodes the server-derived tenant slug', () => {
        renderControl(context('alpha/../beta'));
        const link = screen.getByRole('link', { name: 'System health' });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/system-health');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/system-health');
    });
});
