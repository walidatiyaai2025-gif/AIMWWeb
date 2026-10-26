// exact-head parity materialization retrigger for #923
import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import {
    authoritativeMediaExplorerHref,
    MEDIA_BACK_TO_EXPLORER_OPERATION_ID,
    MediaPagesLinkControl,
} from '../media-pages-link-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

type SiteAwareContext = FrontendContext & {
    active_site?: { id: number; name: string; status: string } | null;
};

function context(overrides: Partial<SiteAwareContext> = {}): SiteAwareContext {
    return {
        user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'content.view', 'sites.view'],
        connectors: [{ key: 'wordpress', state: 'connected', scopes: ['media.read'] }],
        capabilities: {},
        api: { media: '/api/v1/tenants/alpha/sites/17/media' },
        actions: {},
        active_site: { id: 17, name: 'Alpha Site', status: 'active' },
        ...overrides,
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

describe('AIMW-MEDI-8F93BEDD59 MediaManager Back to Explorer navigation', () => {
    it('renders the source-equivalent tenant-qualified explorer destination for the authoritative site', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: 'Back to Explorer' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/explorer?site=17');
        expect(link).toHaveAttribute('data-canonical-operation', MEDIA_BACK_TO_EXPLORER_OPERATION_ID);
        expect(MEDIA_BACK_TO_EXPLORER_OPERATION_ID).toBe('AIMW-MEDI-8F93BEDD59');
    });

    it('derives the destination from active_site and fails closed at the foreign-tenant 404 boundary for mismatched media authority', () => {
        expect(authoritativeMediaExplorerHref(context())).toBe('/tenants/alpha/explorer?site=17');
        expect(authoritativeMediaExplorerHref(context({ api: { media: '/api/v1/tenants/alpha/sites/99/media' } }))).toBeNull();
        expect(authoritativeMediaExplorerHref(context({ api: { media: '/api/v1/tenants/beta/sites/17/media' } }))).toBeNull();
        expect(authoritativeMediaExplorerHref(context({ api: { media: 'https://foreign.example.test/api/v1/tenants/alpha/sites/17/media' } }))).toBeNull();
        expect(authoritativeMediaExplorerHref(context({ api: { media: '/api/v1/tenants/alpha/sites/17/media?site=99' } }))).toBeNull();
        expect(authoritativeMediaExplorerHref(context({ api: { media: '/api/v1/tenants/alpha/sites/17/media#override' } }))).toBeNull();
    });

    it('encodes the authoritative tenant slug rather than permitting a foreign-tenant path escape', () => {
        const value = context({
            tenant: { slug: 'alpha/../beta', name: 'Alpha' },
            api: { media: '/api/v1/tenants/alpha%2F..%2Fbeta/sites/17/media' },
        });

        expect(authoritativeMediaExplorerHref(value)).toBe('/tenants/alpha%2F..%2Fbeta/explorer?site=17');
        expect(authoritativeMediaExplorerHref(value)).not.toContain('/tenants/beta/');
    });

    it.each([
        ['tenant.view is absent', { permissions: ['content.view', 'sites.view'] }],
        ['content.view is absent', { permissions: ['tenant.view', 'sites.view'] }],
        ['sites.view is absent', { permissions: ['tenant.view', 'content.view'] }],
        ['active site is absent', { active_site: null }],
        ['active site is invalid', { active_site: { id: -1, name: 'Invalid', status: 'active' } }],
        ['media API is absent', { api: {} }],
        ['media.read connector scope is absent', { connectors: [] }],
        ['media capability is disabled', { capabilities: { media: { state: 'disabled_by_owner' as const } } }],
        ['explorer capability is disabled', { capabilities: { explorer: { state: 'disabled_by_owner' as const } } }],
    ])('does not render Back to Explorer when %s', (_label, overrides) => {
        renderControl(context(overrides as Partial<SiteAwareContext>));
        expect(screen.queryByRole('link', { name: 'Back to Explorer' })).not.toBeInTheDocument();
    });
});
