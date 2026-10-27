import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import {
    GLOBAL_TAXONOMY_SITE_LINKS_OPERATION_ID,
    GlobalTaxonomySiteLinksControl,
    globalTaxonomySiteDestinations,
} from '../global-taxonomy-site-links-control';
import { LocaleProvider } from '../i18n';

const context = (
    slug = 'alpha',
    permissions = ['tenant.view', 'content.view'],
    api: Record<string, string> = { taxonomy: '/api/v1/tenants/alpha/sites/7/taxonomy' },
): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors: [{ key: 'wordpress', state: 'connected', scopes: ['taxonomy.read'] }],
    capabilities: {},
    api,
    actions: {},
});

function renderControl(value: FrontendContext, siteId: unknown) {
    return render(
        <MemoryRouter>
            <LocaleProvider>
                <GlobalTaxonomySiteLinksControl context={value} siteId={siteId} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

describe('AIMW-TAXO-602DE140EB Global Taxonomy interpolated row links', () => {
    it('renders source-equivalent Manage and Explorer controls for the authoritative row site', () => {
        renderControl(context(), 7);

        const manage = screen.getByRole('link', { name: 'Manage' });
        const explorer = screen.getByRole('link', { name: 'Explorer' });

        expect(manage).toHaveAttribute('href', '/tenants/alpha/module/taxonomy?site=7');
        expect(explorer).toHaveAttribute('href', '/tenants/alpha/explorer?site=7');
        expect(manage.parentElement).toHaveAttribute('data-canonical-operation', GLOBAL_TAXONOMY_SITE_LINKS_OPERATION_ID);
        expect(GLOBAL_TAXONOMY_SITE_LINKS_OPERATION_ID).toBe('AIMW-TAXO-602DE140EB');
    });

    it('keeps a foreign tenant destination behind the server 404 isolation boundary', () => {
        const destinations = globalTaxonomySiteDestinations(context('alpha/../foreign'), 7);
        const foreignTenant404Boundary = '/tenants/foreign/module/taxonomy?site=7';

        expect(destinations).not.toBeNull();
        expect(destinations?.manage).not.toBe(foreignTenant404Boundary);
        expect(destinations?.manage).toContain('/tenants/alpha%2F..%2Fforeign/');
        expect(destinations?.explorer).toContain('/tenants/alpha%2F..%2Fforeign/');
    });

    it('encodes the tenant slug and rejects non-authoritative site identifiers', () => {
        expect(globalTaxonomySiteDestinations(context('alpha/../foreign'), 7)).toEqual({
            manage: '/tenants/alpha%2F..%2Fforeign/module/taxonomy?site=7',
            explorer: '/tenants/alpha%2F..%2Fforeign/explorer?site=7',
        });

        for (const bad of [null, undefined, 0, -1, 1.5, ' 7', '7 ', '../7', '0']) {
            expect(globalTaxonomySiteDestinations(context(), bad)).toBeNull();
        }
    });

    it.each([
        ['tenant permission missing', ['content.view'], { taxonomy: '/api/v1/tenants/alpha/sites/7/taxonomy' }],
        ['content permission missing', ['tenant.view'], { taxonomy: '/api/v1/tenants/alpha/sites/7/taxonomy' }],
        ['taxonomy API contract missing', ['tenant.view', 'content.view'], {}],
    ])('fails closed when %s', (_label, permissions, api) => {
        renderControl(context('alpha', permissions, api), 7);
        expect(screen.queryByRole('link', { name: 'Manage' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Explorer' })).not.toBeInTheDocument();
    });
});
