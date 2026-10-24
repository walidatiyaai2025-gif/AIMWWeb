import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';
import {
    normalizeAuthoritativeSiteId,
    SITES_INTERPOLATED_LINKS_OPERATION_ID,
    SitesInterpolatedLinksControl,
    sitesInterpolatedDestinations,
} from '../sites-interpolated-links-control';

function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha team', name: 'Alpha Team' },
        tenants: [{ slug: 'alpha team', name: 'Alpha Team' }],
        permissions: ['tenant.view', 'sites.view'],
        connectors: [],
        capabilities: {},
        api: { sites: '/api/tenants/alpha%20team/sites' },
        actions: {},
    };
}

const route: WorkspaceRoute = {
    key: 'sites',
    path: '/sites',
    group: 'overview',
    icon: '◉',
    label: { en: 'Sites', ar: 'المواقع' },
    description: { en: 'Manage connected WordPress sites.', ar: 'إدارة مواقع WordPress المتصلة.' },
    apiKey: 'sites',
    permission: 'sites.view',
    controls: [],
    kind: 'resource',
};

function renderWorkspace() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <ToastProvider>
                    <WorkspacePage context={context()} route={route} />
                </ToastProvider>
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe(`${SITES_INTERPOLATED_LINKS_OPERATION_ID} Sites interpolated links`, () => {
    it('renders source-equivalent dashboard and content navigation from the authoritative Sites row id', async () => {
        const fetchMock = vi.fn().mockResolvedValueOnce(new Response(JSON.stringify([
            {
                id: 11,
                name: 'Alpha site',
                url: 'https://alpha.example.test',
                site_url: 'https://alpha.example.test',
                status: 'active',
                connection_status: 'connected',
            },
        ]), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        const dashboard = await screen.findByRole('link', { name: 'Dashboard' });
        const contentLink = screen.getByRole('link', { name: 'Content' });

        expect(dashboard).toHaveAttribute('href', '/tenants/alpha%20team/sites/11');
        expect(contentLink).toHaveAttribute('href', '/tenants/alpha%20team/explorer?site=11');
        expect(dashboard.parentElement).toHaveAttribute('data-canonical-operation', SITES_INTERPOLATED_LINKS_OPERATION_ID);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls.every(([, options]) => !options || options.method === undefined || options.method === 'GET')).toBe(true);
    });

    it('fails closed instead of synthesizing links for a missing or malformed site id', () => {
        for (const siteId of [null, undefined, 0, -1, 1.5, '0', '01', ' 7', '7 ', 'abc', '../7']) {
            const { unmount } = render(
                <LocaleProvider>
                    <SitesInterpolatedLinksControl tenantSlug="alpha" siteId={siteId} />
                </LocaleProvider>,
            );
            expect(screen.queryByRole('link')).not.toBeInTheDocument();
            unmount();
        }
    });
});

describe('Sites interpolated destination derivation', () => {
    it('uses only a positive authoritative site id and the active tenant slug', () => {
        expect(normalizeAuthoritativeSiteId(42)).toBe('42');
        expect(normalizeAuthoritativeSiteId('42')).toBe('42');
        expect(normalizeAuthoritativeSiteId('0042')).toBeNull();
        expect(sitesInterpolatedDestinations('alpha team', 42)).toEqual({
            dashboard: '/tenants/alpha%20team/sites/42',
            explorer: '/tenants/alpha%20team/explorer?site=42',
        });
        expect(sitesInterpolatedDestinations('', 42)).toBeNull();
    });
});
