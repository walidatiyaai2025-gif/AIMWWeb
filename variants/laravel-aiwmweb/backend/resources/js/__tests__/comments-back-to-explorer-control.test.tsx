import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    authoritativeCommentsExplorerHref,
    COMMENTS_BACK_TO_EXPLORER_OPERATION_ID,
    CommentsBackToExplorerControl,
} from '../comments-back-to-explorer-control';
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
        permissions: ['tenant.view', 'content.view', 'sites.view'],
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
            <CommentsBackToExplorerControl context={value} />
        </LocaleProvider>,
    );
}

describe('AIMW-COMM-2B682F7BEC Comments Back to Explorer navigation', () => {
    it('renders the source-equivalent navigation for the server-authoritative selected site', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: 'Back to Explorer' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/explorer?site=17');
        expect(link).toHaveAttribute('data-canonical-operation', COMMENTS_BACK_TO_EXPLORER_OPERATION_ID);
        expect(COMMENTS_BACK_TO_EXPLORER_OPERATION_ID).toBe('AIMW-COMM-2B682F7BEC');
    });

    it('derives the destination from active_site and rejects mismatched or caller-overridden comments authority', () => {
        expect(authoritativeCommentsExplorerHref(context())).toBe('/tenants/alpha/explorer?site=17');
        expect(authoritativeCommentsExplorerHref(context({ api: { comments: '/api/v1/tenants/alpha/sites/99/comments' } }))).toBeNull();
        expect(authoritativeCommentsExplorerHref(context({ api: { comments: '/api/v1/tenants/beta/sites/17/comments' } }))).toBeNull();
        expect(authoritativeCommentsExplorerHref(context({ api: { comments: 'https://foreign.example.test/api/v1/tenants/alpha/sites/17/comments' } }))).toBeNull();
        expect(authoritativeCommentsExplorerHref(context({ api: { comments: '/api/v1/tenants/alpha/sites/17/comments?site=99' } }))).toBeNull();
        expect(authoritativeCommentsExplorerHref(context({ api: { comments: '/api/v1/tenants/alpha/sites/17/comments#override' } }))).toBeNull();
    });

    it('encodes the authoritative tenant slug instead of permitting a foreign-tenant path escape', () => {
        const value = context({
            tenant: { slug: 'alpha/../beta', name: 'Alpha' },
            api: { comments: '/api/v1/tenants/alpha%2F..%2Fbeta/sites/17/comments' },
        });

        expect(authoritativeCommentsExplorerHref(value)).toBe('/tenants/alpha%2F..%2Fbeta/explorer?site=17');
        expect(authoritativeCommentsExplorerHref(value)).not.toContain('/tenants/beta/');
    });

    it.each([
        ['tenant.view is absent', { permissions: ['content.view', 'sites.view'] }],
        ['content.view is absent', { permissions: ['tenant.view', 'sites.view'] }],
        ['sites.view is absent', { permissions: ['tenant.view', 'content.view'] }],
        ['active site is absent', { active_site: null }],
        ['active site is invalid', { active_site: { id: -1, name: 'Invalid', status: 'active' } }],
        ['comments API is absent', { api: {} }],
        ['comments.read connector scope is absent', { connectors: [] }],
        ['comments capability is disabled', { capabilities: { comments: { state: 'disabled_by_owner' as const } } }],
        ['explorer capability is disabled', { capabilities: { explorer: { state: 'disabled_by_owner' as const } } }],
    ])('fails closed when %s', (_label, overrides) => {
        renderControl(context(overrides as Partial<SiteAwareContext>));
        expect(screen.queryByRole('link', { name: 'Back to Explorer' })).not.toBeInTheDocument();
    });
});
