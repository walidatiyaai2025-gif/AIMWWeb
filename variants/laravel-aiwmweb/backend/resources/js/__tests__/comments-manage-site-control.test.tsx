import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    authoritativeCommentsManageHref,
    COMMENTS_MANAGE_SITE_OPERATION_ID,
    CommentsManageSiteControl,
} from '../comments-manage-site-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

type SiteAwareContext = FrontendContext & {
    active_site?: { id: number; name: string; status: string } | null;
};

const commentsConnector: FrontendContext['connectors'] = [
    { key: 'wordpress', state: 'connected', scopes: ['comments.read'] },
];

function context(overrides: Partial<SiteAwareContext> = {}): SiteAwareContext {
    return {
        user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'content.view'],
        connectors: commentsConnector,
        capabilities: {},
        api: { comments: '/api/v1/tenants/alpha/sites/17/comments' },
        actions: {},
        active_site: { id: 17, name: 'Alpha Site', status: 'active' },
        ...overrides,
    };
}

function renderControl(value: FrontendContext) {
    return render(
        <LocaleProvider>
            <CommentsManageSiteControl context={value} />
        </LocaleProvider>,
    );
}

describe('AIMW-COMM-C083D47BC4 Global Comments Manage navigation', () => {
    it('renders the source-equivalent Manage control for the server-authoritative selected site', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: 'Manage' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites/17/comments');
        expect(link).toHaveAttribute('data-canonical-operation', COMMENTS_MANAGE_SITE_OPERATION_ID);
        expect(COMMENTS_MANAGE_SITE_OPERATION_ID).toBe('AIMW-COMM-C083D47BC4');
    });

    it('derives the destination from active_site and rejects a mismatched caller-visible comments contract', () => {
        expect(authoritativeCommentsManageHref(context())).toBe('/tenants/alpha/sites/17/comments');
        expect(authoritativeCommentsManageHref(context({ api: { comments: '/api/v1/tenants/alpha/sites/99/comments' } }))).toBeNull();
        expect(authoritativeCommentsManageHref(context({ api: { comments: '/api/v1/tenants/beta/sites/17/comments' } }))).toBeNull();
        expect(authoritativeCommentsManageHref(context({ api: { comments: 'https://foreign.example.test/api/v1/tenants/alpha/sites/17/comments' } }))).toBeNull();
        expect(authoritativeCommentsManageHref(context({ api: { comments: '/api/v1/tenants/alpha/sites/17/comments?site=99' } }))).toBeNull();
        expect(authoritativeCommentsManageHref(context({ api: { comments: '/api/v1/tenants/alpha/sites/17/comments#override' } }))).toBeNull();
    });

    it('encodes the authoritative tenant slug rather than allowing a foreign-tenant path escape', () => {
        const value = context({
            tenant: { slug: 'alpha/../beta', name: 'Alpha' },
            api: { comments: '/api/v1/tenants/alpha%2F..%2Fbeta/sites/17/comments' },
        });

        expect(authoritativeCommentsManageHref(value)).toBe('/tenants/alpha%2F..%2Fbeta/sites/17/comments');
        expect(authoritativeCommentsManageHref(value)).not.toBe('/tenants/beta/sites/17/comments');
    });

    it.each([
        ['tenant.view is absent', { permissions: ['content.view'] }],
        ['content.view is absent', { permissions: ['tenant.view'] }],
        ['active site is absent', { active_site: null }],
        ['active site is invalid', { active_site: { id: -1, name: 'Invalid', status: 'active' } }],
        ['comments API is absent', { api: {} }],
        ['comments.read connector scope is absent', { connectors: [] }],
        ['comments capability is disabled', { capabilities: { comments: { state: 'disabled_by_owner' as const } } }],
    ])('fails closed when %s', (_label, overrides) => {
        renderControl(context(overrides as Partial<SiteAwareContext>));
        expect(screen.queryByRole('link', { name: 'Manage' })).not.toBeInTheDocument();
    });
});
