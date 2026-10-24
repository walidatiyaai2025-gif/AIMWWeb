import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it } from 'vitest';
import { type FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITE_OPERATIONS_SITES_LINK_OPERATION_ID,
    SiteOperationsSitesLinkControl,
} from '../site-operations-sites-link-control';

function context(
    slug = 'alpha',
    permissions: string[] = ['tenant.view', 'sites.view', 'execution.view'],
    tenantSlugs: string[] = [slug],
): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug, name: 'Active tenant' },
        tenants: tenantSlugs.map((value) => ({ slug: value, name: value })),
        permissions,
        connectors: [],
        capabilities: {},
        api: { sites: `/api/tenants/${encodeURIComponent(slug)}/sites` },
        actions: {},
    };
}

function renderControl(value: FrontendContext) {
    return render(
        <MemoryRouter>
            <LocaleProvider>
                <SiteOperationsSitesLinkControl context={value} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

afterEach(() => cleanup());

describe('AIMW-CONT-5BE26C7AB5 Site Operations Manage sites control', () => {
    it('renders the exact operation and tenant-scoped Sites destination', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: /manage sites/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites');
        expect(link).toHaveAttribute('data-canonical-operation', SITE_OPERATIONS_SITES_LINK_OPERATION_ID);
        expect(SITE_OPERATIONS_SITES_LINK_OPERATION_ID).toBe('AIMW-CONT-5BE26C7AB5');
    });

    it('derives and encodes the destination only from active tenant context', () => {
        renderControl(context('tenant with space'));
        expect(screen.getByRole('link', { name: /manage sites/i }))
            .toHaveAttribute('href', '/tenants/tenant%20with%20space/sites');
    });

    it('fails closed without destination permission', () => {
        renderControl(context('alpha', ['tenant.view', 'execution.view']));
        expect(screen.queryByRole('link', { name: /manage sites/i })).not.toBeInTheDocument();
    });

    it('fails closed when the active tenant is absent from authenticated memberships', () => {
        renderControl(context('alpha', undefined, ['beta']));
        expect(screen.queryByRole('link', { name: /manage sites/i })).not.toBeInTheDocument();
    });
});
