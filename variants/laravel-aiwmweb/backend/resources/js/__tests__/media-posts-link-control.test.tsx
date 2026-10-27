import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { MEDIA_POSTS_OPERATION_ID, MediaPagesLinkControl } from '../media-pages-link-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const mediaAndPostsConnector: FrontendContext['connectors'] = [
    { key: 'wordpress', state: 'connected', scopes: ['media.read', 'posts.read'] },
];

function context(
    slug = 'alpha',
    permissions = ['tenant.view', 'content.view'],
    api: Record<string, string> = {
        media: '/api/v1/tenants/alpha/sites/17/media',
        posts: '/api/v1/tenants/alpha/sites/17/posts',
    },
    capabilities: FrontendContext['capabilities'] = {},
    connectors: FrontendContext['connectors'] = mediaAndPostsConnector,
): FrontendContext {
    return {
        user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
        tenant: { slug, name: 'Alpha' },
        tenants: [{ slug, name: 'Alpha' }],
        permissions,
        connectors,
        capabilities,
        api,
        actions: {},
    };
}

function renderControl(value: FrontendContext) {
    return render(
        <MemoryRouter>
            <LocaleProvider>
                <MediaPagesLinkControl context={value} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

describe('AIMW-MEDI-F55A549341 GlobalMediaExplorer Posts navigation', () => {
    it('renders the source-equivalent tenant-qualified Posts link from the canonical Media workspace', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: 'Posts' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/module/posts');
        expect(link).toHaveAttribute('data-canonical-operation', MEDIA_POSTS_OPERATION_ID);
        expect(MEDIA_POSTS_OPERATION_ID).toBe('AIMW-MEDI-F55A549341');
    });

    it('encodes a hostile tenant slug so a foreign tenant path stays behind the server 404 tenant boundary', () => {
        renderControl(context('alpha/../foreign'));

        const link = screen.getByRole('link', { name: 'Posts' });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fforeign/module/posts');
        expect(link.getAttribute('href')).not.toBe('/tenants/foreign/module/posts');
    });

    it.each([
        ['tenant.view is missing', ['content.view'], { media: '/api/v1/tenants/alpha/sites/17/media', posts: '/api/v1/tenants/alpha/sites/17/posts' }, {}, mediaAndPostsConnector],
        ['content.view is missing', ['tenant.view'], { media: '/api/v1/tenants/alpha/sites/17/media', posts: '/api/v1/tenants/alpha/sites/17/posts' }, {}, mediaAndPostsConnector],
        ['authoritative Media API is missing', ['tenant.view', 'content.view'], { posts: '/api/v1/tenants/alpha/sites/17/posts' }, {}, mediaAndPostsConnector],
        ['authoritative Posts API is missing', ['tenant.view', 'content.view'], { media: '/api/v1/tenants/alpha/sites/17/media' }, {}, mediaAndPostsConnector],
        ['WordPress read scopes are unavailable', ['tenant.view', 'content.view'], { media: '/api/v1/tenants/alpha/sites/17/media', posts: '/api/v1/tenants/alpha/sites/17/posts' }, {}, []],
        ['Media capability is disabled', ['tenant.view', 'content.view'], { media: '/api/v1/tenants/alpha/sites/17/media', posts: '/api/v1/tenants/alpha/sites/17/posts' }, { media: { state: 'disabled_by_owner' as const } }, mediaAndPostsConnector],
        ['Posts capability is disabled', ['tenant.view', 'content.view'], { media: '/api/v1/tenants/alpha/sites/17/media', posts: '/api/v1/tenants/alpha/sites/17/posts' }, { posts: { state: 'disabled_by_owner' as const } }, mediaAndPostsConnector],
    ])('fails closed when %s', (_label, permissions, api, capabilities, connectors) => {
        renderControl(context('alpha', permissions, api, capabilities, connectors));
        expect(screen.queryByRole('link', { name: 'Posts' })).not.toBeInTheDocument();
    });
});
