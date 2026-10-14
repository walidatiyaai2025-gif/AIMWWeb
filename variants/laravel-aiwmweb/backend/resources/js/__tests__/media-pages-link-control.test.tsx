import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { MEDIA_PAGES_OPERATION_ID, MediaPagesLinkControl } from '../media-pages-link-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const mediaAndPagesConnector: FrontendContext['connectors'] = [
    { key: 'wordpress', state: 'connected', scopes: ['media.read', 'pages.read'] },
];

function context(
    slug = 'alpha',
    permissions = ['tenant.view', 'content.view'],
    api: Record<string, string> = {
        media: '/api/v1/tenants/alpha/sites/17/media',
        pages: '/api/v1/tenants/alpha/sites/17/pages',
    },
    capabilities: FrontendContext['capabilities'] = {},
    connectors: FrontendContext['connectors'] = mediaAndPagesConnector,
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

describe('AIMW-MEDI-0694CEEBBA GlobalMediaExplorer Pages navigation', () => {
    it('renders the source-equivalent tenant-qualified Pages link from the canonical Media workspace', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: 'Pages' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/module/pages');
        expect(link).toHaveAttribute('data-canonical-operation', MEDIA_PAGES_OPERATION_ID);
        expect(MEDIA_PAGES_OPERATION_ID).toBe('AIMW-MEDI-0694CEEBBA');
    });

    it('encodes a hostile tenant slug so a foreign tenant path stays behind the server 404 tenant boundary', () => {
        renderControl(context('alpha/../foreign'));

        const link = screen.getByRole('link', { name: 'Pages' });
        const foreignTenant404Boundary = '/tenants/foreign/module/pages';
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fforeign/module/pages');
        expect(link.getAttribute('href')).not.toBe(foreignTenant404Boundary);
    });

    it.each([
        ['tenant.view is missing', ['content.view'], { media: '/api/v1/tenants/alpha/sites/17/media', pages: '/api/v1/tenants/alpha/sites/17/pages' }, {}, mediaAndPagesConnector],
        ['content.view is missing', ['tenant.view'], { media: '/api/v1/tenants/alpha/sites/17/media', pages: '/api/v1/tenants/alpha/sites/17/pages' }, {}, mediaAndPagesConnector],
        ['authoritative Media API is missing', ['tenant.view', 'content.view'], { pages: '/api/v1/tenants/alpha/sites/17/pages' }, {}, mediaAndPagesConnector],
        ['authoritative Pages API is missing', ['tenant.view', 'content.view'], { media: '/api/v1/tenants/alpha/sites/17/media' }, {}, mediaAndPagesConnector],
        ['WordPress read scopes are unavailable', ['tenant.view', 'content.view'], { media: '/api/v1/tenants/alpha/sites/17/media', pages: '/api/v1/tenants/alpha/sites/17/pages' }, {}, []],
        ['Media capability is disabled', ['tenant.view', 'content.view'], { media: '/api/v1/tenants/alpha/sites/17/media', pages: '/api/v1/tenants/alpha/sites/17/pages' }, { media: { state: 'disabled_by_owner' as const } }, mediaAndPagesConnector],
        ['Pages capability is disabled', ['tenant.view', 'content.view'], { media: '/api/v1/tenants/alpha/sites/17/media', pages: '/api/v1/tenants/alpha/sites/17/pages' }, { pages: { state: 'disabled_by_owner' as const } }, mediaAndPagesConnector],
    ])('fails closed when %s', (_label, permissions, api, capabilities, connectors) => {
        renderControl(context('alpha', permissions, api, capabilities, connectors));
        expect(screen.queryByRole('link', { name: 'Pages' })).not.toBeInTheDocument();
    });
});
