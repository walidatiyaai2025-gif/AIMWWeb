import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';
import {
    SITES_SITE_URL_LINK_OPERATION_ID,
    SitesSiteUrlLinkControl,
    safeExternalSiteUrl,
} from '../sites-site-url-link-control';

function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view'],
        connectors: [],
        capabilities: {},
        api: { sites: '/api/tenants/alpha/sites' },
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

describe(`${SITES_SITE_URL_LINK_OPERATION_ID} Sites SiteUrl link`, () => {
    it('renders the authoritative Sites row URL as the source-equivalent external link', async () => {
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

        const link = await screen.findByRole('link', { name: 'https://alpha.example.test' });
        expect(link).toHaveAttribute('href', 'https://alpha.example.test');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        expect(link).toHaveAttribute('data-canonical-operation', SITES_SITE_URL_LINK_OPERATION_ID);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('uses the legacy authoritative url field when the semantic site_url alias is absent', async () => {
        const fetchMock = vi.fn().mockResolvedValueOnce(new Response(JSON.stringify([
            { id: 12, name: 'Legacy row', url: 'https://legacy.example.test', status: 'active' },
        ]), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        expect(await screen.findByRole('link', { name: 'https://legacy.example.test' }))
            .toHaveAttribute('data-canonical-operation', SITES_SITE_URL_LINK_OPERATION_ID);
    });

    it('fails closed for unsafe, credential-bearing, relative and malformed URLs', () => {
        for (const value of [
            'javascript:alert(1)',
            'data:text/html,x',
            '/relative',
            'https://user:secret@example.test',
            ' not-a-url',
        ]) {
            const { unmount } = render(
                <SitesSiteUrlLinkControl value={value} />,
            );
            expect(screen.queryByRole('link')).not.toBeInTheDocument();
            unmount();
        }
    });
});

describe('safeExternalSiteUrl', () => {
    it('allows only absolute credential-free HTTP(S)', () => {
        expect(safeExternalSiteUrl('https://alpha.example.test')).toBe('https://alpha.example.test');
        expect(safeExternalSiteUrl('http://alpha.example.test')).toBe('http://alpha.example.test');
        expect(safeExternalSiteUrl('ftp://alpha.example.test')).toBeNull();
        expect(safeExternalSiteUrl('https://u:p@alpha.example.test')).toBeNull();
    });
});
