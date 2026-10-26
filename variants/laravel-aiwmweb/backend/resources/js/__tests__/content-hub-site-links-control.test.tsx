import React from 'react';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    authoritativeContentHubSitesEndpoint,
    CONTENT_HUB_SITE_LINKS_OPERATION_ID,
    ContentHubSiteLinksControl,
    normalizeContentHubSiteRows,
} from '../content-hub-site-links-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

function context(overrides: Partial<FrontendContext> = {}): FrontendContext {
    return {
        user: { id: 7, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'content.view', 'sites.view'],
        connectors: [],
        capabilities: {},
        api: { sites: '/api/tenants/alpha/sites' },
        actions: {},
        ...overrides,
    };
}

function renderControl(value = context()) {
    const client = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    return render(
        <QueryClientProvider client={client}>
            <MemoryRouter>
                <LocaleProvider>
                    <ContentHubSiteLinksControl context={value} />
                </LocaleProvider>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('AIMW-CONT-276925F148 GlobalContentHub dynamic site links', () => {
    it('reads the authoritative tenant sites endpoint and renders both source-equivalent links', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify([
            { id: 7, name: 'Alpha Site' },
            { id: 8, name: 'Beta Site' },
            { id: '../9', name: 'Unsafe Site' },
        ]), {
            status: 200,
            headers: { 'content-type': 'application/json' },
        }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        const siteLink = await screen.findByRole('link', { name: 'Alpha Site' });
        expect(siteLink).toHaveAttribute('href', '/tenants/alpha/sites/7');
        expect(siteLink).toHaveAttribute('data-canonical-operation', CONTENT_HUB_SITE_LINKS_OPERATION_ID);
        expect(siteLink).toHaveAttribute('data-source-control', 'site-details');

        const contentLinks = screen.getAllByRole('link', { name: 'Content' });
        expect(contentLinks).toHaveLength(2);
        expect(contentLinks[0]).toHaveAttribute('href', '/tenants/alpha/explorer?site=7');
        expect(contentLinks[0]).toHaveAttribute('data-canonical-operation', CONTENT_HUB_SITE_LINKS_OPERATION_ID);
        expect(contentLinks[0]).toHaveAttribute('data-source-control', 'site-explorer');
        expect(screen.queryByText('Unsafe Site')).not.toBeInTheDocument();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/api/tenants/alpha/sites');
        expect(fetchMock.mock.calls[0][1]?.method).toBeUndefined();
        expect(fetchMock.mock.calls[0][1]?.body).toBeUndefined();
    });

    it('keeps deterministic loading, empty and error states without synthesizing rows', async () => {
        let release!: (value: Response) => void;
        vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>((resolve) => {
            release = resolve;
        })));

        renderControl();
        expect(screen.getByText('Loading content sites…')).toBeInTheDocument();

        release(new Response(JSON.stringify([]), {
            status: 200,
            headers: { 'content-type': 'application/json' },
        }));
        expect(await screen.findByText('No accessible sites were returned.')).toBeInTheDocument();

        cleanup();
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ message: 'Sites unavailable.' }), {
            status: 503,
            headers: { 'content-type': 'application/json' },
        })));

        renderControl();
        expect(await screen.findByRole('alert')).toHaveTextContent('Sites unavailable.');
        expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();
    });

    it('fails closed for missing authority or a non-authoritative endpoint', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        renderControl(context({ permissions: ['tenant.view', 'content.view'] }));
        await waitFor(() => expect(fetchMock).not.toHaveBeenCalled());
        expect(screen.queryByLabelText('Content hub site links')).not.toBeInTheDocument();

        cleanup();
        renderControl(context({ api: { sites: 'https://foreign.example.test/api/tenants/alpha/sites' } }));
        await waitFor(() => expect(fetchMock).not.toHaveBeenCalled());
        expect(screen.queryByLabelText('Content hub site links')).not.toBeInTheDocument();
    });

    it('normalizes only real unique site identifiers and validates the exact tenant endpoint', () => {
        expect(CONTENT_HUB_SITE_LINKS_OPERATION_ID).toBe('AIMW-CONT-276925F148');
        expect(authoritativeContentHubSitesEndpoint(context())).toBe('/api/tenants/alpha/sites');
        expect(authoritativeContentHubSitesEndpoint(context({ api: { sites: '/api/tenants/beta/sites' } }))).toBeNull();

        expect(normalizeContentHubSiteRows({
            data: [
                { id: 4, name: 'Four' },
                { id: '4', name: 'Duplicate' },
                { id: 0, name: 'Zero' },
                { id: 5, name: '   ' },
            ],
        })).toEqual([{ id: '4', name: 'Four' }]);
    });
});
