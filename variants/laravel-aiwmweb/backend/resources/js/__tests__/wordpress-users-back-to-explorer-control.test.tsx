import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    authoritativeWordPressUsersExplorerHref,
    WORDPRESS_USERS_BACK_TO_EXPLORER_OPERATION_ID,
    WordPressUsersBackToExplorerControl,
} from '../wordpress-users-back-to-explorer-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

type SiteAwareContext = FrontendContext & {
    active_site?: { id: number; name: string; status: string } | null;
};

const usersConnector: FrontendContext['connectors'] = [
    { key: 'wordpress', state: 'connected', scopes: ['users.read'] },
];

function context(overrides: Partial<SiteAwareContext> = {}): SiteAwareContext {
    return {
        user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'content.view', 'sites.view'],
        connectors: usersConnector,
        capabilities: {},
        api: { 'wp-users': '/api/v1/tenants/alpha/sites/17/users' },
        actions: {},
        active_site: { id: 17, name: 'Alpha Site', status: 'active' },
        ...overrides,
    };
}

function renderControl(value: FrontendContext) {
    return render(
        <LocaleProvider>
            <WordPressUsersBackToExplorerControl context={value} />
        </LocaleProvider>,
    );
}

describe('AIMW-CONT-D5FFC84EC2 WordPress Users Back to Explorer navigation', () => {
    it('renders source-equivalent navigation for the server-authoritative selected site', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: 'Back to Explorer' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/explorer?site=17');
        expect(link).toHaveAttribute('data-canonical-operation', WORDPRESS_USERS_BACK_TO_EXPLORER_OPERATION_ID);
        expect(WORDPRESS_USERS_BACK_TO_EXPLORER_OPERATION_ID).toBe('AIMW-CONT-D5FFC84EC2');
    });

    it('derives destination from active_site and rejects mismatched or caller-overridden users authority', () => {
        expect(authoritativeWordPressUsersExplorerHref(context())).toBe('/tenants/alpha/explorer?site=17');
        expect(authoritativeWordPressUsersExplorerHref(context({ api: { 'wp-users': '/api/v1/tenants/alpha/sites/99/users' } }))).toBeNull();
        expect(authoritativeWordPressUsersExplorerHref(context({ api: { 'wp-users': '/api/v1/tenants/beta/sites/17/users' } }))).toBeNull();
        expect(authoritativeWordPressUsersExplorerHref(context({ api: { 'wp-users': 'https://foreign.example.test/api/v1/tenants/alpha/sites/17/users' } }))).toBeNull();
        expect(authoritativeWordPressUsersExplorerHref(context({ api: { 'wp-users': '/api/v1/tenants/alpha/sites/17/users?site=99' } }))).toBeNull();
        expect(authoritativeWordPressUsersExplorerHref(context({ api: { 'wp-users': '/api/v1/tenants/alpha/sites/17/users#override' } }))).toBeNull();
    });

    it('encodes the authoritative tenant slug instead of permitting a foreign-tenant path escape', () => {
        const value = context({
            tenant: { slug: 'alpha/../beta', name: 'Alpha' },
            api: { 'wp-users': '/api/v1/tenants/alpha%2F..%2Fbeta/sites/17/users' },
        });

        expect(authoritativeWordPressUsersExplorerHref(value)).toBe('/tenants/alpha%2F..%2Fbeta/explorer?site=17');
        expect(authoritativeWordPressUsersExplorerHref(value)).not.toContain('/tenants/beta/');
    });

    it.each([
        ['tenant.view is absent', { permissions: ['content.view', 'sites.view'] }],
        ['sites.view is absent', { permissions: ['tenant.view', 'content.view'] }],
        ['active site is absent', { active_site: null }],
        ['active site is invalid', { active_site: { id: -1, name: 'Invalid', status: 'active' } }],
        ['users API is absent', { api: {} }],
        ['users.read connector scope is absent', { connectors: [] }],
        ['users capability is disabled', { capabilities: { 'wp-users': { state: 'disabled_by_owner' as const } } }],
        ['explorer capability is disabled', { capabilities: { explorer: { state: 'disabled_by_owner' as const } } }],
    ])('fails closed when %s', (_label, overrides) => {
        renderControl(context(overrides as Partial<SiteAwareContext>));
        expect(screen.queryByRole('link', { name: 'Back to Explorer' })).not.toBeInTheDocument();
    });
});
