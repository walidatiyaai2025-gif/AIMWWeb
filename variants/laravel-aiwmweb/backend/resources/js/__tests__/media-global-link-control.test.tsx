import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { MEDIA_GLOBAL_OPERATION_ID, MediaPagesLinkControl } from '../media-pages-link-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

function context(
    slug = 'alpha',
    permissions = ['tenant.view', 'content.view'],
    api: Record<string, string> = {
        media: '/api/v1/tenants/alpha/sites/17/media',
    },
    capabilities: FrontendContext['capabilities'] = {},
): FrontendContext {
    return {
        user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
        tenant: { slug, name: 'Alpha' },
        tenants: [{ slug, name: 'Alpha' }],
        permissions,
        connectors: [{ key: 'wordpress', state: 'connected', scopes: ['media.read'] }],
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

describe('AIMW-MEDI-56F4EF0AFB MediaManager Global media navigation', () => {
    it('renders the source-equivalent tenant-qualified Global media link from authoritative site media context', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: 'Global media' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/module/media');
        expect(link).toHaveAttribute('data-canonical-operation', MEDIA_GLOBAL_OPERATION_ID);
        expect(MEDIA_GLOBAL_OPERATION_ID).toBe('AIMW-MEDI-56F4EF0AFB');
    });

    it('encodes a hostile tenant slug so a foreign tenant path stays behind the server 404 tenant boundary', () => {
        renderControl(context('alpha/../foreign'));

        const link = screen.getByRole('link', { name: 'Global media' });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fforeign/module/media');
        expect(link.getAttribute('href')).not.toBe('/tenants/foreign/module/media');
    });

    it.each([
        ['tenant.view is missing', ['content.view'], { media: '/api/v1/tenants/alpha/sites/17/media' }, {}],
        ['content.view is missing', ['tenant.view'], { media: '/api/v1/tenants/alpha/sites/17/media' }, {}],
        ['authoritative site Media API is missing', ['tenant.view', 'content.view'], {}, {}],
        ['Media capability is disabled', ['tenant.view', 'content.view'], { media: '/api/v1/tenants/alpha/sites/17/media' }, { media: { state: 'disabled_by_owner' as const } }],
    ])('fails closed when %s', (_label, permissions, api, capabilities) => {
        renderControl(context('alpha', permissions, api, capabilities));
        expect(screen.queryByRole('link', { name: 'Global media' })).not.toBeInTheDocument();
    });
});
