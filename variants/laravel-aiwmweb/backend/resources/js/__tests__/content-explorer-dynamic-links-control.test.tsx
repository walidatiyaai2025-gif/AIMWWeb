import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    CONTENT_EXPLORER_DYNAMIC_LINKS_OPERATION_ID,
    ContentExplorerDynamicLinksControl,
    contentExplorerDynamicTargets,
    contentExplorerEditorHref,
} from '../content-explorer-dynamic-links-control';

type ContextWithActiveSite = FrontendContext & {
    active_site?: { id: number; name: string } | null;
};

const context = (
    slug = 'alpha',
    permissions = ['tenant.view', 'sites.view', 'content.view', 'seo.view'],
): ContextWithActiveSite => ({
    user: { id: 1, name: 'Alpha User', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
    active_site: { id: 41, name: 'Alpha Site' },
});

function renderControl(value: ContextWithActiveSite) {
    const client = new QueryClient({
        defaultOptions: { queries: { retry: false } },
    });

    return render(
        <MemoryRouter>
            <QueryClientProvider client={client}>
                <LocaleProvider>
                    <ContentExplorerDynamicLinksControl context={value} />
                </LocaleProvider>
            </QueryClientProvider>
        </MemoryRouter>,
    );
}

afterEach(() => {
    cleanup();
    window.localStorage.clear();
});

describe('AIMW-CONT-9BA2C255AF Content Explorer dynamic href parity', () => {
    it('derives site, taxonomy and SEO destinations from authoritative tenant/site context', () => {
        const targets = contentExplorerDynamicTargets(context(), 41);

        expect(targets).toEqual({
            site: '/tenants/alpha/sites/41',
            taxonomy: '/tenants/alpha/module/taxonomy?site=41',
            seo: '/tenants/alpha/module/seo-audit?site=41',
        });
    });

    it('builds the existing real content-editor route without accepting an arbitrary destination', () => {
        expect(contentExplorerEditorHref(context(), 41, 'post', 902))
            .toBe('/tenants/alpha/sites/41/content/post/902/edit');
        expect(contentExplorerEditorHref(context(), 0, 'post', 902)).toBeNull();
        expect(contentExplorerEditorHref(context(), 41, 'page', 0)).toBeNull();
    });

    it('renders the canonical marker and dynamic site links on the explorer surface', () => {
        const { container } = renderControl(context());

        expect(container.querySelector('[data-canonical-operation]'))
            .toHaveAttribute('data-canonical-operation', CONTENT_EXPLORER_DYNAMIC_LINKS_OPERATION_ID);
        expect(screen.getByRole('link', { name: 'Site details' }))
            .toHaveAttribute('href', '/tenants/alpha/sites/41');
        expect(screen.getByRole('link', { name: 'Manage taxonomy' }))
            .toHaveAttribute('href', '/tenants/alpha/module/taxonomy?site=41');
        expect(screen.getByRole('link', { name: 'SEO' }))
            .toHaveAttribute('href', '/tenants/alpha/module/seo-audit?site=41');
    });

    it('encodes tenant-shaped input and fails closed when there is no active site', () => {
        const targets = contentExplorerDynamicTargets(context('alpha/../foreign'), 41);
        expect(targets?.site).toBe('/tenants/alpha%2F..%2Fforeign/sites/41');

        const noSite = context();
        noSite.active_site = null;
        const { container } = renderControl(noSite);
        expect(container).toBeEmptyDOMElement();
    });

    it('does not expose content or SEO destinations without their permissions', () => {
        renderControl(context('alpha', ['tenant.view', 'sites.view']));

        expect(screen.getByRole('link', { name: 'Site details' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Manage taxonomy' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'SEO' })).not.toBeInTheDocument();
    });
});
