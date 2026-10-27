import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    authoritativeContentExplorerCategoriesHref,
    CONTENT_EXPLORER_CATEGORIES_TAB_OPERATION_ID,
    ContentExplorerCategoriesTabControl,
} from '../content-explorer-categories-tab-control';
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
            <ContentExplorerCategoriesTabControl context={value} />
        </LocaleProvider>,
    );
}

describe('AIMW-TAXO-A96714F338 ContentExplorer SetCategoriesTab', () => {
    it('opens the canonical taxonomy workspace on authoritative Categories state for the selected site', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: /Categories/ });
        expect(link).toHaveAttribute('href', '/tenants/alpha/module/taxonomy?site=17');
        expect(link).toHaveAttribute('data-canonical-operation', CONTENT_EXPLORER_CATEGORIES_TAB_OPERATION_ID);
        expect(CONTENT_EXPLORER_CATEGORIES_TAB_OPERATION_ID).toBe('AIMW-TAXO-A96714F338');
    });

    it('rejects mismatched, cross-origin, query-overridden, and fragment-overridden taxonomy authority', () => {
        expect(authoritativeContentExplorerCategoriesHref(context({ api: { taxonomy: '/api/v1/tenants/alpha/sites/99/taxonomy' } }))).toBeNull();
        expect(authoritativeContentExplorerCategoriesHref(context({ api: { taxonomy: '/api/v1/tenants/beta/sites/17/taxonomy' } }))).toBeNull();
        expect(authoritativeContentExplorerCategoriesHref(context({ api: { taxonomy: 'https://foreign.example.test/api/v1/tenants/alpha/sites/17/taxonomy' } }))).toBeNull();
        expect(authoritativeContentExplorerCategoriesHref(context({ api: { taxonomy: '/api/v1/tenants/alpha/sites/17/taxonomy?taxonomy=post_tag' } }))).toBeNull();
        expect(authoritativeContentExplorerCategoriesHref(context({ api: { taxonomy: '/api/v1/tenants/alpha/sites/17/taxonomy#foreign' } }))).toBeNull();
    });

    it('keeps a hostile foreign tenant slug behind the server 404 tenant isolation boundary', () => {
        const value = context({
            tenant: { slug: 'alpha/../foreign', name: 'Alpha' },
            api: { taxonomy: '/api/v1/tenants/alpha%2F..%2Fforeign/sites/17/taxonomy' },
        });

        expect(authoritativeContentExplorerCategoriesHref(value)).toBe('/tenants/alpha%2F..%2Fforeign/module/taxonomy?site=17');
        expect(authoritativeContentExplorerCategoriesHref(value)).not.toContain('/tenants/foreign/');
    });

    it.each([
        ['tenant.view is absent', { permissions: ['sites.view', 'content.view'] }],
        ['sites.view is absent', { permissions: ['tenant.view', 'content.view'] }],
        ['content.view is absent', { permissions: ['tenant.view', 'sites.view'] }],
        ['active site is absent', { active_site: null }],
        ['active site is invalid', { active_site: { id: -1, name: 'Invalid', status: 'active' } }],
        ['taxonomy API is absent', { api: {} }],
        ['taxonomy.read connector scope is absent', { connectors: [] }],
        ['taxonomy capability is disabled', { capabilities: { taxonomy: { state: 'disabled_by_owner' as const } } }],
    ])('fails closed when %s', (_label, overrides) => {
        renderControl(context(overrides as Partial<SiteAwareContext>));
        expect(screen.queryByRole('link', { name: /Categories/ })).not.toBeInTheDocument();
    });
});
