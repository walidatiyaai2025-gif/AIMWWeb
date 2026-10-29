import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITES_SYSTEM_HEALTH_LINK_OPERATION_ID,
    SitesSystemHealthLinkControl,
} from '../sites-system-health-link-control';

function context(
    slug = 'alpha',
    permissions: string[] = ['tenant.view', 'sites.view', 'diagnostics.view'],
    tenantSlugs: string[] = [slug],
    userId = 10,
): FrontendContext {
    return {
        user: { id: userId, name: 'Alpha Operator', email: 'alpha@example.test' },
        tenant: { slug, name: 'Alpha' },
        tenants: tenantSlugs.map((tenantSlug) => ({ slug: tenantSlug, name: tenantSlug })),
        permissions,
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
    };
}

function renderControl(value: FrontendContext) {
    return render(
        <LocaleProvider>
            <SitesSystemHealthLinkControl context={value} />
        </LocaleProvider>,
    );
}

afterEach(() => cleanup());

describe('AIMW-CONT-97AD37042A Sites System health navigation', () => {
    it('navigates to the authoritative tenant-scoped System health workspace', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: 'System health' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/system-health');
        expect(link).toHaveAttribute('data-canonical-operation', SITES_SYSTEM_HEALTH_LINK_OPERATION_ID);
        expect(SITES_SYSTEM_HEALTH_LINK_OPERATION_ID).toBe('AIMW-CONT-97AD37042A');
        expect(link).not.toHaveAttribute('data-method');
    });

    it('fails closed when either tenant.view or diagnostics.view is absent', () => {
        const diagnosticsMissing = renderControl(context('alpha', ['tenant.view', 'sites.view']));
        expect(screen.queryByRole('link', { name: 'System health' })).not.toBeInTheDocument();
        diagnosticsMissing.unmount();

        renderControl(context('alpha', ['sites.view', 'diagnostics.view']));
        expect(screen.queryByRole('link', { name: 'System health' })).not.toBeInTheDocument();
    });

    it('fails closed for foreign active tenant membership or invalid user identity', () => {
        const foreign = renderControl(context('alpha', undefined, ['beta']));
        expect(screen.queryByRole('link', { name: 'System health' })).not.toBeInTheDocument();
        foreign.unmount();

        renderControl(context('alpha', undefined, ['alpha'], 0));
        expect(screen.queryByRole('link', { name: 'System health' })).not.toBeInTheDocument();
    });

    it('URL-encodes the authoritative tenant slug', () => {
        renderControl(context('alpha/../beta'));

        const link = screen.getByRole('link', { name: 'System health' });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/system-health');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/system-health');
    });

    it('accepts wildcard authorization without weakening tenant identity checks', () => {
        renderControl(context('alpha', ['*']));
        expect(screen.getByRole('link', { name: 'System health' }))
            .toHaveAttribute('href', '/tenants/alpha/system-health');
    });
});
