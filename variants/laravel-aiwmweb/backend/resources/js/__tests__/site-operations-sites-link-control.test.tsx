import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { type FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITE_OPERATIONS_SITES_LINK_OPERATION_ID,
    SiteOperationsSitesLinkControl,
} from '../site-operations-sites-link-control';

function context(slug = 'alpha'): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug, name: 'Active tenant' },
        tenants: [{ slug, name: 'Active tenant' }],
        permissions: ['tenant.view', 'sites.view', 'execution.view'],
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
    };
}

describe('canonical Site Operations Manage sites control', () => {
    it('renders the exact operation and tenant-scoped Sites destination', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <SiteOperationsSitesLinkControl context={context()} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        const link = screen.getByRole('link', { name: /manage sites/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites');
        expect(link).toHaveAttribute('data-canonical-operation', SITE_OPERATIONS_SITES_LINK_OPERATION_ID);
        expect(SITE_OPERATIONS_SITES_LINK_OPERATION_ID).toBe('AIMW-CONT-5BE26C7AB5');
    });

    it('derives and encodes the destination only from active tenant context', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <SiteOperationsSitesLinkControl context={context('tenant with space')} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        expect(screen.getByRole('link', { name: /manage sites/i }))
            .toHaveAttribute('href', '/tenants/tenant%20with%20space/sites');
    });
});
