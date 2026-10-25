import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    authoritativeContentExplorerMediaEndpoint,
    CONTENT_EXPLORER_MEDIA_SOURCE_LINK_OPERATION_ID,
    ContentExplorerMediaSourceLinkControl,
    contentExplorerMediaSourcePageEndpoint,
} from '../content-explorer-media-source-link-control';

type SiteAwareContext = FrontendContext & {
    active_site?: { id: number; name: string; status: string } | null;
};

function context(overrides: Partial<SiteAwareContext> = {}): SiteAwareContext {
    return {
        user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view', 'content.view'],
        connectors: [{ key: 'wordpress', state: 'connected', scopes: ['media.read'] }],
        capabilities: {},
        api: {
            explorer: '/api/v1/tenants/alpha/sites/17/explorer',
            media: '/api/v1/tenants/alpha/sites/17/media',
        },
        actions: {},
        active_site: { id: 17, name: 'Alpha Site', status: 'active' },
        ...overrides,
    };
}

function renderControl(activeContext: FrontendContext = context()) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <ContentExplorerMediaSourceLinkControl context={activeContext} />
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('AIMW-CONT-B44DA81C28 Content Explorer media SourceUrl', () => {
    it('reads the authoritative media collection and renders only safe persisted SourceUrl destinations', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            data: [
                {
                    id: 1,
                    remote_id: 501,
                    title: 'hero.jpg',
                    mime_type: 'image/jpeg',
                    source_url: 'https://alpha.example.test/uploads/hero.jpg',
                },
                {
                    id: 2,
                    remote_id: 502,
                    title: 'unsafe.svg',
                    mime_type: 'image/svg+xml',
                    source_url: 'javascript:alert(1)',
                },
            ],
        }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        const link = await screen.findByRole('link', { name: 'Open file hero.jpg' });
        expect(link).toHaveAttribute('href', 'https://alpha.example.test/uploads/hero.jpg');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        expect(link).toHaveAttribute('data-canonical-operation', CONTENT_EXPLORER_MEDIA_SOURCE_LINK_OPERATION_ID);
        expect(CONTENT_EXPLORER_MEDIA_SOURCE_LINK_OPERATION_ID).toBe('AIMW-CONT-B44DA81C28');
        expect(screen.getByLabelText('Media file link unavailable')).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/v1/tenants/alpha/sites/17/media?per_page=100&page=1');
        expect(fetchMock.mock.calls[0]?.[1]).not.toHaveProperty('method');
    });

    it('has deterministic empty and error states without synthesizing links', async () => {
        const emptyFetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: [] }), {
            status: 200,
            headers: { 'content-type': 'application/json' },
        }));
        vi.stubGlobal('fetch', emptyFetch);
        const empty = renderControl();

        expect(await screen.findByText('No media is available.')).toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        empty.unmount();

        const errorFetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ message: 'upstream unavailable' }), {
            status: 503,
            headers: { 'content-type': 'application/json' },
        }));
        vi.stubGlobal('fetch', errorFetch);
        renderControl();

        expect(await screen.findByRole('alert')).toHaveTextContent('Media links could not be loaded.');
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('fails closed for mismatched, cross-origin, overridden and unauthorized media authority', () => {
        expect(authoritativeContentExplorerMediaEndpoint(context())).toBe('/api/v1/tenants/alpha/sites/17/media');
        expect(authoritativeContentExplorerMediaEndpoint(context({
            api: { explorer: '/api/v1/tenants/alpha/sites/17/explorer', media: '/api/v1/tenants/alpha/sites/99/media' },
        }))).toBeNull();
        expect(authoritativeContentExplorerMediaEndpoint(context({
            api: { explorer: '/api/v1/tenants/alpha/sites/17/explorer', media: 'https://foreign.example.test/api/v1/tenants/alpha/sites/17/media' },
        }))).toBeNull();
        expect(authoritativeContentExplorerMediaEndpoint(context({
            api: { explorer: '/api/v1/tenants/alpha/sites/17/explorer', media: '/api/v1/tenants/alpha/sites/17/media?site=99' },
        }))).toBeNull();
        expect(authoritativeContentExplorerMediaEndpoint(context({
            permissions: ['tenant.view', 'sites.view'],
        }))).toBeNull();
        expect(authoritativeContentExplorerMediaEndpoint(context({
            connectors: [],
        }))).toBeNull();
        expect(authoritativeContentExplorerMediaEndpoint(context({
            active_site: null,
        }))).toBeNull();
    });

    it('does not fetch when the authoritative contract is absent', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        renderControl(context({ api: {} }));

        expect(fetchMock).not.toHaveBeenCalled();
        expect(screen.queryByLabelText('Media file links')).not.toBeInTheDocument();
    });

    it('builds the read-only first-page endpoint deterministically', () => {
        expect(contentExplorerMediaSourcePageEndpoint('/api/v1/tenants/alpha/sites/17/media'))
            .toBe('/api/v1/tenants/alpha/sites/17/media?per_page=100&page=1');
    });

    it('rejects credential-bearing, relative and unsafe persisted SourceUrl values through the shared guard', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            data: [
                { remote_id: 1, title: 'credentials', source_url: 'https://user:secret@example.test/file' },
                { remote_id: 2, title: 'relative', source_url: '/uploads/file.jpg' },
                { remote_id: 3, title: 'data', source_url: 'data:text/html,hello' },
            ],
        }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();
        await waitFor(() => expect(screen.getAllByLabelText('Media file link unavailable')).toHaveLength(3));

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
});
