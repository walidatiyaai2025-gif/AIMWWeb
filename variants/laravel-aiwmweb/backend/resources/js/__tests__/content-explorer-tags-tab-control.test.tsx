import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    authoritativeContentExplorerTagsHref,
    CONTENT_EXPLORER_TAGS_TAB_OPERATION_ID,
    ContentExplorerTagsTabControl,
    requestedTaxonomyTypeFromLocation,
} from '../content-explorer-tags-tab-control';
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
            <ContentExplorerTagsTabControl context={value} />
        </LocaleProvider>,
    );
}

describe('AIMW-TAXO-D6126926AD ContentExplorer SetTagsTab', () => {
    it('opens authoritative post_tag taxonomy for the selected site', () => {
        renderControl(context());
        const link = screen.getByRole('link', { name: /Tags/ });
        expect(link).toHaveAttribute('href', '/tenants/alpha/module/taxonomy?site=17&taxonomy=post_tag');
        expect(link).toHaveAttribute('data-canonical-operation', CONTENT_EXPLORER_TAGS_TAB_OPERATION_ID);
        expect(CONTENT_EXPLORER_TAGS_TAB_OPERATION_ID).toBe('AIMW-TAXO-D6126926AD');
    });

    it('reads post_tag from the taxonomy workspace location', () => {
        window.history.replaceState({}, '', '/tenants/alpha/module/taxonomy?site=17&taxonomy=post_tag');
        expect(requestedTaxonomyTypeFromLocation()).toBe('post_tag');
        window.history.replaceState({}, '', '/tenants/alpha/module/taxonomy?site=17&taxonomy=category');
        expect(requestedTaxonomyTypeFromLocation()).toBe('category');
    });

    it('rejects foreign, mismatched and overridden taxonomy authority', () => {
        expect(authoritativeContentExplorerTagsHref(context({ api: { taxonomy: '/api/v1/tenants/alpha/sites/99/taxonomy' } }))).toBeNull();
        expect(authoritativeContentExplorerTagsHref(context({ api: { taxonomy: '/api/v1/tenants/beta/sites/17/taxonomy' } }))).toBeNull();
        expect(authoritativeContentExplorerTagsHref(context({ api: { taxonomy: 'https://foreign.example.test/api/v1/tenants/alpha/sites/17/taxonomy' } }))).toBeNull();
        expect(authoritativeContentExplorerTagsHref(context({ api: { taxonomy: '/api/v1/tenants/alpha/sites/17/taxonomy?site=99' } }))).toBeNull();
    });

    it.each([
        ['tenant.view is absent', { permissions: ['sites.view', 'content.view'] }],
        ['sites.view is absent', { permissions: ['tenant.view', 'content.view'] }],
        ['content.view is absent', { permissions: ['tenant.view', 'sites.view'] }],
        ['active site is absent', { active_site: null }],
        ['taxonomy API is absent', { api: {} }],
        ['taxonomy.read connector scope is absent', { connectors: [] }],
    ])('fails closed when %s', (_label, overrides) => {
        renderControl(context(overrides as Partial<SiteAwareContext>));
        expect(screen.queryByRole('link', { name: /Tags/ })).not.toBeInTheDocument();
    });
});

// exact-head gate retrigger after synchronized #998 materialization
