import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITE_CONNECTION_CENTER_SITE_URL_OPERATION_ID,
    SiteConnectionCenterSyncControl,
} from '../site-connection-center-sync-control';

function context(): FrontendContext {
    return {
        user: { id: 7, name: 'Reader', email: 'reader@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view'],
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
    };
}

function renderControl() {
    return render(
        <LocaleProvider>
            <MemoryRouter initialEntries={['/tenants/alpha/sites/7/connection']}>
                <Routes>
                    <Route
                        path="/tenants/:tenantSlug/sites/:siteId/connection"
                        element={<SiteConnectionCenterSyncControl context={context()} />}
                    />
                </Routes>
            </MemoryRouter>
        </LocaleProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('AIMW-CONT-63EDB4BA6F SiteConnectionCenter SiteUrl', () => {
    it('renders the authoritative tenant-scoped SiteUrl as a safe external link', async () => {
        const connection = {
            site: {
                id: 7,
                name: 'Alpha Site',
                url: 'https://alpha.test',
                connection_status: 'connected',
            },
            connection: 'CONNECTED',
            last_verified_at: '2026-10-25T04:00:00Z',
        };

        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify(connection), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ items: [] }), { status: 200, headers: { 'content-type': 'application/json' } })));

        renderControl();

        expect(screen.getByText('Loading site URL…')).toHaveAttribute('aria-busy', 'true');

        const link = await screen.findByRole('link', { name: /Visit site/i });
        expect(link).toHaveAttribute('href', 'https://alpha.test');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');

        const region = screen.getByRole('region', { name: 'WordPress site URL' });
        expect(region).toHaveAttribute('data-canonical-operation', SITE_CONNECTION_CENTER_SITE_URL_OPERATION_ID);
        expect(SITE_CONNECTION_CENTER_SITE_URL_OPERATION_ID).toBe('AIMW-CONT-63EDB4BA6F');
    });

    it('fails closed instead of emitting an unsafe persisted URL', async () => {
        const connection = {
            site: {
                id: 7,
                name: 'Alpha Site',
                url: 'javascript:alert(1)',
                connection_status: 'connected',
            },
            connection: 'CONNECTED',
        };

        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify(connection), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ items: [] }), { status: 200, headers: { 'content-type': 'application/json' } })));

        renderControl();

        expect(await screen.findByText('Site URL unavailable.')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /Visit site/i })).not.toBeInTheDocument();
    });
});
