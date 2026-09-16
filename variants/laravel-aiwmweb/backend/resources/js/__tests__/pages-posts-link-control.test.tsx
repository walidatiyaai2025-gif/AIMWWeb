import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { PAGES_POSTS_OPERATION_ID, PagesPostsLinkControl } from '../pages-posts-link-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const connectedPostsConnector: FrontendContext['connectors'] = [
    { key: 'wordpress', state: 'connected', scopes: ['posts.read'] },
];

const context = (
    slug = 'alpha',
    permissions = ['tenant.view', 'content.view'],
    api: Record<string, string> = { posts: '/tenants/alpha/api/posts' },
    capabilities: FrontendContext['capabilities'] = {},
    connectors: FrontendContext['connectors'] = connectedPostsConnector,
): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors,
    capabilities,
    api,
    actions: {},
});

function renderControl(value: FrontendContext) {
    return render(
        <MemoryRouter>
            <LocaleProvider>
                <PagesPostsLinkControl context={value} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

describe('AIMW-CONT-058F41BD1B Pages to Posts navigation', () => {
    it('renders the source-equivalent Posts control for the authoritative active tenant', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: 'Posts' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/module/posts');
        expect(link).toHaveAttribute('data-canonical-operation', PAGES_POSTS_OPERATION_ID);
        expect(PAGES_POSTS_OPERATION_ID).toBe('AIMW-CONT-058F41BD1B');
    });

    it('encodes the tenant slug so a foreign tenant path remains behind the server 404 isolation boundary', () => {
        renderControl(context('alpha/../foreign'));

        const link = screen.getByRole('link', { name: 'Posts' });
        const foreignTenant404Boundary = '/tenants/foreign/module/posts';
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fforeign/module/posts');
        expect(link.getAttribute('href')).not.toBe(foreignTenant404Boundary);
    });

    it.each([
        ['tenant context permission is missing', ['content.view'], { posts: '/tenants/alpha/api/posts' }, {}, connectedPostsConnector],
        ['content permission is missing', ['tenant.view'], { posts: '/tenants/alpha/api/posts' }, {}, connectedPostsConnector],
        ['the authoritative posts API contract is missing', ['tenant.view', 'content.view'], {}, {}, connectedPostsConnector],
        ['the Posts connector scope is unavailable', ['tenant.view', 'content.view'], { posts: '/tenants/alpha/api/posts' }, {}, []],
        ['the server disables the posts capability', ['tenant.view', 'content.view'], { posts: '/tenants/alpha/api/posts' }, { posts: { state: 'disabled_by_owner' as const } }, connectedPostsConnector],
    ])('fails closed when %s', (_label, permissions, api, capabilities, connectors) => {
        renderControl(context('alpha', permissions, api, capabilities, connectors));
        expect(screen.queryByRole('link', { name: 'Posts' })).not.toBeInTheDocument();
    });
});
