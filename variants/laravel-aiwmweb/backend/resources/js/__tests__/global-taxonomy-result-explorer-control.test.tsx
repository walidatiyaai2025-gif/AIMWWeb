import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    GLOBAL_TAXONOMY_RESULT_EXPLORER_OPERATION_ID,
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
        permissions: ['tenant.view', 'sites.view', 'content.view'],
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

describe('AIMW-TAXO-602DE140EB GlobalTaxonomy result Explorer navigation', () => {
    it('marks the authoritative site Explorer navigation with the exact global-taxonomy operation', () => {
        renderControl(context());

        const nav = screen.getByRole('navigation', { name: 'Back to site explorer' });
        const link = screen.getByRole('link', { name: 'Back to Explorer' });
        expect(nav).toHaveAttribute('data-canonical-operation', GLOBAL_TAXONOMY_RESULT_EXPLORER_OPERATION_ID);
        expect(GLOBAL_TAXONOMY_RESULT_EXPLORER_OPERATION_ID).toBe('AIMW-TAXO-602DE140EB');
        expect(link).toHaveAttribute('href', '/tenants/alpha/explorer?site=17');
    });

    it('keeps a hostile tenant slug encoded behind the server 404 boundary', () => {
        renderControl(context({
            tenant: { slug: 'alpha/../foreign', name: 'Alpha' },
            api: { taxonomy: '/api/v1/tenants/alpha%2F..%2Fforeign/sites/17/taxonomy' },
        }));

        const link = screen.getByRole('link', { name: 'Back to Explorer' });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fforeign/explorer?site=17');
        expect(link.getAttribute('href')).not.toContain('/tenants/foreign/');
    });

    it.each([
        ['tenant.view is absent', { permissions: ['sites.view', 'content.view'] }],
        ['sites.view is absent', { permissions: ['tenant.view', 'content.view'] }],
        ['content.view is absent', { permissions: ['tenant.view', 'sites.view'] }],
        ['active site is absent', { active_site: null }],
        ['taxonomy API is absent', { api: {} }],
        ['taxonomy.read scope is absent', { connectors: [] }],
        ['taxonomy API points at another site', { api: { taxonomy: '/api/v1/tenants/alpha/sites/99/taxonomy' } }],
    ])('fails closed when %s', (_label, overrides) => {
        renderControl(context(overrides as Partial<SiteAwareContext>));
        expect(screen.queryByRole('navigation', { name: 'Back to site explorer' })).not.toBeInTheDocument();
    });
});

// exact-head gate retrigger after parity materialization

// exact-head gate retrigger after synchronized parity materialization
// exact-head synchronize event after materialized #999 parity
