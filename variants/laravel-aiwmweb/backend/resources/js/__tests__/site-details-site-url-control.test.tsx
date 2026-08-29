import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITE_DETAILS_SITE_URL_OPERATION_ID,
    SiteDetailsSiteUrlControl,
    safeExternalSiteUrl,
} from '../site-details-site-url-control';

function context(siteId = '17'): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view'],
        connectors: [],
        capabilities: {},
        api: { [`sites.detail.${siteId}`]: `/api/tenants/alpha/sites/${siteId}` },
        actions: {},
    };
}

function renderControl(activeContext = context(), siteId = '17') {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <MemoryRouter initialEntries={[`/tenants/alpha/sites/${siteId}`]}>
                    <Routes>
                        <Route
                            path="/tenants/:tenantSlug/sites/:siteId"
                            element={<SiteDetailsSiteUrlControl context={activeContext} />}
                        />
                    </Routes>
                </MemoryRouter>
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('canonical Site Details external Site URL control', () => {
    it('reads the authoritative tenant-scoped Site Details endpoint and renders the persisted URL', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            id: 17,
            name: 'Alpha Site',
            url: 'https://alpha.example.test',
            status: 'active',
        }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        const link = await screen.findByRole('link', { name: /https:\/\/alpha\.example\.test/i });
        expect(link).toHaveAttribute('href', 'https://alpha.example.test');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        expect(link.closest('section')).toHaveAttribute('data-canonical-operation', SITE_DETAILS_SITE_URL_OPERATION_ID);
        expect(SITE_DETAILS_SITE_URL_OPERATION_ID).toBe('AIMW-AI-A8D10964C6');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/tenants/alpha/sites/17');
        expect(fetchMock.mock.calls[0]?.[1]).not.toHaveProperty('method');
    });

    it('fails closed for a non-http persisted destination instead of creating a clickable control', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
            id: 17,
            url: 'javascript:alert(1)',
        }), { status: 200, headers: { 'content-type': 'application/json' } })));

        renderControl();

        expect(await screen.findByText('LIVE SITE')).toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('does not fetch or synthesize a destination when the exact Site Details API contract is absent', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const activeContext = context();
        activeContext.api = { sites: '/api/tenants/alpha/sites' };

        renderControl(activeContext);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
});

describe('safeExternalSiteUrl', () => {
    it('preserves validated http/https values and rejects caller-dangerous schemes or malformed strings', () => {
        expect(safeExternalSiteUrl('https://alpha.example.test/path')).toBe('https://alpha.example.test/path');
        expect(safeExternalSiteUrl('http://alpha.example.test')).toBe('http://alpha.example.test');
        expect(safeExternalSiteUrl('javascript:alert(1)')).toBeNull();
        expect(safeExternalSiteUrl('data:text/html,hello')).toBeNull();
        expect(safeExternalSiteUrl(' https://alpha.example.test')).toBeNull();
        expect(safeExternalSiteUrl('not a url')).toBeNull();
    });
});
