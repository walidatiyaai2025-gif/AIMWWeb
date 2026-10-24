import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import {
    canonicalExternalSiteUrl,
    SITES_URL_LINK_OPERATION_ID,
    WorkspacePage,
} from '../pages';

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

describe(`${SITES_URL_LINK_OPERATION_ID} Sites external URL link`, () => {
    it('renders the authoritative site URL as a safe external read-only link', async () => {
        const fetchMock = vi.fn().mockResolvedValueOnce(new Response(JSON.stringify([
            {
                id: 11,
                name: 'Alpha Site',
                url: 'https://alpha-site.test/',
                status: 'active',
                connection_status: 'connected',
            },
        ]), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        const link = await screen.findByRole('link', { name: 'https://alpha-site.test/' });
        expect(link).toHaveAttribute('href', 'https://alpha-site.test/');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        expect(link).toHaveAttribute('data-canonical-operation', SITES_URL_LINK_OPERATION_ID);

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(String(fetchMock.mock.calls[0]?.[0])).toContain('/api/tenants/alpha/sites');
        expect(fetchMock.mock.calls.every(([, options]) => !options || options.method === undefined || options.method === 'GET')).toBe(true);
    });

    it('does not turn unsafe or malformed authoritative values into navigation controls', async () => {
        const fetchMock = vi.fn().mockResolvedValueOnce(new Response(JSON.stringify([
            {
                id: 12,
                name: 'Unsafe Site',
                url: 'javascript:alert(1)',
                status: 'active',
                connection_status: 'unknown',
            },
        ]), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        expect(await screen.findByText('javascript:alert(1)')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'javascript:alert(1)' })).not.toBeInTheDocument();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    });

    it('accepts only http and https external destinations', () => {
        expect(canonicalExternalSiteUrl('https://example.test/path')).toBe('https://example.test/path');
        expect(canonicalExternalSiteUrl('http://example.test')).toBe('http://example.test/');
        expect(canonicalExternalSiteUrl('javascript:alert(1)')).toBeNull();
        expect(canonicalExternalSiteUrl('mailto:test@example.test')).toBeNull();
        expect(canonicalExternalSiteUrl('not a url')).toBeNull();
        expect(canonicalExternalSiteUrl(null)).toBeNull();
    });
});
