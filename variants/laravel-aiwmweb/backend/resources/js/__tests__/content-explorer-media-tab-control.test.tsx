import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    authoritativeContentExplorerMediaHref,
    CONTENT_EXPLORER_MEDIA_TAB_OPERATION_ID,
    ContentExplorerMediaTabControl,
} from '../content-explorer-media-tab-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

type SiteAwareContext = FrontendContext & {
    active_site?: { id: number; name: string; status: string } | null;
};

const mediaConnector: FrontendContext['connectors'] = [
    { key: 'wordpress', state: 'connected', scopes: ['media.read'] },
];

function context(overrides: Partial<SiteAwareContext> = {}): SiteAwareContext {
    return {
        user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view', 'content.view'],
        connectors: mediaConnector,
        capabilities: {},
        api: { media: '/api/v1/tenants/alpha/sites/17/media' },
        actions: {},
        active_site: { id: 17, name: 'Alpha Site', status: 'active' },
        ...overrides,
    };
}

function renderControl(value: FrontendContext) {
    return render(
        <LocaleProvider>
            <ContentExplorerMediaTabControl context={value} />
        </LocaleProvider>,
    );
}

describe('AIMW-MEDI-73C0DA6B3D ContentExplorer SetMediaTab', () => {
    it('opens the canonical Media Explorer for the authoritative selected site', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: /Media/ });
        expect(link).toHaveAttribute('href', '/tenants/alpha/module/media?site=17');
        expect(link).toHaveAttribute('data-canonical-operation', CONTENT_EXPLORER_MEDIA_TAB_OPERATION_ID);
        expect(CONTENT_EXPLORER_MEDIA_TAB_OPERATION_ID).toBe('AIMW-MEDI-73C0DA6B3D');
    });

    it('rejects mismatched, cross-origin, query-overridden, and fragment-overridden media authority', () => {
        expect(authoritativeContentExplorerMediaHref(context({ api: { media: '/api/v1/tenants/alpha/sites/99/media' } }))).toBeNull();
        expect(authoritativeContentExplorerMediaHref(context({ api: { media: '/api/v1/tenants/beta/sites/17/media' } }))).toBeNull();
        expect(authoritativeContentExplorerMediaHref(context({ api: { media: 'https://foreign.example.test/api/v1/tenants/alpha/sites/17/media' } }))).toBeNull();
        expect(authoritativeContentExplorerMediaHref(context({ api: { media: '/api/v1/tenants/alpha/sites/17/media?site=99' } }))).toBeNull();
        expect(authoritativeContentExplorerMediaHref(context({ api: { media: '/api/v1/tenants/alpha/sites/17/media#foreign' } }))).toBeNull();
    });

    it('keeps a hostile foreign tenant slug behind the server 404 tenant isolation boundary', () => {
        const value = context({
            tenant: { slug: 'alpha/../foreign', name: 'Alpha' },
            api: { media: '/api/v1/tenants/alpha%2F..%2Fforeign/sites/17/media' },
        });

        expect(authoritativeContentExplorerMediaHref(value)).toBe('/tenants/alpha%2F..%2Fforeign/module/media?site=17');
        expect(authoritativeContentExplorerMediaHref(value)).not.toContain('/tenants/foreign/');
    });

    it.each([
        ['tenant.view is absent', { permissions: ['sites.view', 'content.view'] }],
        ['sites.view is absent', { permissions: ['tenant.view', 'content.view'] }],
        ['content.view is absent', { permissions: ['tenant.view', 'sites.view'] }],
        ['active site is absent', { active_site: null }],
        ['active site is invalid', { active_site: { id: -1, name: 'Invalid', status: 'active' } }],
        ['media API is absent', { api: {} }],
        ['media.read connector scope is absent', { connectors: [] }],
        ['media capability is disabled', { capabilities: { media: { state: 'disabled_by_owner' as const } } }],
    ])('fails closed when %s', (_label, overrides) => {
        renderControl(context(overrides as Partial<SiteAwareContext>));
        expect(screen.queryByRole('link', { name: /Media/ })).not.toBeInTheDocument();
    });
});
