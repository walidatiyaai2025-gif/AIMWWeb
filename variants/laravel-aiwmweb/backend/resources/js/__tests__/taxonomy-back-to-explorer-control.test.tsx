import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    authoritativeTaxonomyExplorerHref,
    TAXONOMY_BACK_TO_EXPLORER_OPERATION_ID,
    TaxonomyBackToExplorerControl,
} from '../taxonomy-back-to-explorer-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

type SiteAwareContext = FrontendContext & {
    active_site?: { id: number; name: string; status: string } | null;
};

const taxonomyConnector: FrontendContext['connectors'] = [
    { key: 'wordpress', state: 'connected', scopes: ['taxonomy.read'] },
];

function context(overrides: Partial<SiteAwareContext> = {}): SiteAwareContext {
    return {
        user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'content.view', 'sites.view'],
        connectors: taxonomyConnector,
        capabilities: {},
        api: { taxonomy: '/api/v1/tenants/alpha/sites/17/taxonomy' },
        actions: {},
        active_site: { id: 17, name: 'Alpha Site', status: 'active' },
        ...overrides,
    };
}

function renderControl(value: FrontendContext) {
    return render(
        <LocaleProvider>
            <TaxonomyBackToExplorerControl context={value} />
        </LocaleProvider>,
    );
}

describe('AIMW-TAXO-ECDD00F787 Taxonomy Back to Explorer navigation', () => {
    it('renders the source-equivalent navigation for the server-authoritative selected site', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: 'Back to Explorer' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/explorer?site=17');
        expect(link).toHaveAttribute('data-canonical-operation', TAXONOMY_BACK_TO_EXPLORER_OPERATION_ID);
        expect(TAXONOMY_BACK_TO_EXPLORER_OPERATION_ID).toBe('AIMW-TAXO-ECDD00F787');
    });

    it('derives the destination from active_site and rejects mismatched or caller-overridden taxonomy authority', () => {
        expect(authoritativeTaxonomyExplorerHref(context())).toBe('/tenants/alpha/explorer?site=17');
        expect(authoritativeTaxonomyExplorerHref(context({ api: { taxonomy: '/api/v1/tenants/alpha/sites/99/taxonomy' } }))).toBeNull();
        expect(authoritativeTaxonomyExplorerHref(context({ api: { taxonomy: '/api/v1/tenants/beta/sites/17/taxonomy' } }))).toBeNull();
        expect(authoritativeTaxonomyExplorerHref(context({ api: { taxonomy: 'https://foreign.example.test/api/v1/tenants/alpha/sites/17/taxonomy' } }))).toBeNull();
        expect(authoritativeTaxonomyExplorerHref(context({ api: { taxonomy: '/api/v1/tenants/alpha/sites/17/taxonomy?site=99' } }))).toBeNull();
        expect(authoritativeTaxonomyExplorerHref(context({ api: { taxonomy: '/api/v1/tenants/alpha/sites/17/taxonomy#override' } }))).toBeNull();
    });

    it('encodes the authoritative tenant slug instead of permitting a foreign-tenant path escape', () => {
        const value = context({
            tenant: { slug: 'alpha/../beta', name: 'Alpha' },
            api: { taxonomy: '/api/v1/tenants/alpha%2F..%2Fbeta/sites/17/taxonomy' },
        });

        expect(authoritativeTaxonomyExplorerHref(value)).toBe('/tenants/alpha%2F..%2Fbeta/explorer?site=17');
        expect(authoritativeTaxonomyExplorerHref(value)).not.toContain('/tenants/beta/');
    });

    it.each([
        ['tenant.view is absent', { permissions: ['content.view', 'sites.view'] }],
        ['content.view is absent', { permissions: ['tenant.view', 'sites.view'] }],
        ['sites.view is absent', { permissions: ['tenant.view', 'content.view'] }],
        ['active site is absent', { active_site: null }],
        ['active site is invalid', { active_site: { id: -1, name: 'Invalid', status: 'active' } }],
        ['taxonomy API is absent', { api: {} }],
        ['taxonomy.read connector scope is absent', { connectors: [] }],
        ['taxonomy capability is disabled', { capabilities: { taxonomy: { state: 'disabled_by_owner' as const } } }],
        ['explorer capability is disabled', { capabilities: { explorer: { state: 'disabled_by_owner' as const } } }],
    ])('fails closed when %s', (_label, overrides) => {
        renderControl(context(overrides as Partial<SiteAwareContext>));
        expect(screen.queryByRole('link', { name: 'Back to Explorer' })).not.toBeInTheDocument();
    });
});
