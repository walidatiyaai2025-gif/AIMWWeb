import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITE_CONNECTION_CENTER_BACK_TO_SITES_OPERATION_ID,
    SiteConnectionCenterSyncControl,
} from '../site-connection-center-sync-control';

function context(slug = 'alpha'): FrontendContext {
    return {
        user: { id: 7, name: 'Operator', email: 'operator@example.test' },
        tenant: { slug, name: slug },
        tenants: [{ slug, name: slug }],
        permissions: ['tenant.view', 'sites.view'],
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
    };
}

function renderControl(active = context()) {
    return render(
        <LocaleProvider>
            <MemoryRouter initialEntries={[`/tenants/${active.tenant.slug}/sites/7/connection`]}>
                <Routes>
                    <Route
                        path="/tenants/:tenantSlug/sites/:siteId/connection"
                        element={<SiteConnectionCenterSyncControl context={active} />}
                    />
                </Routes>
            </MemoryRouter>
        </LocaleProvider>,
    );
}

function json(payload: unknown, status: number) {
    return new Response(JSON.stringify(payload), {
        status,
        headers: { 'content-type': 'application/json' },
    });
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('AIMW-CONT-8C19E4E3B2 Site Connection Center Back to sites', () => {
    it('renders the canonical tenant-qualified Sites destination only for authoritative not-found state', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(json({ message: 'Site not found.' }, 404))
            .mockResolvedValueOnce(json({ items: [] }, 200)));

        renderControl();

        const link = await screen.findByRole('link', { name: 'Back to sites' });
        expect(SITE_CONNECTION_CENTER_BACK_TO_SITES_OPERATION_ID).toBe('AIMW-CONT-8C19E4E3B2');
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites');
        expect(link).toHaveAttribute(
            'data-canonical-operation',
            SITE_CONNECTION_CENTER_BACK_TO_SITES_OPERATION_ID,
        );
        expect(link).toHaveAttribute('data-testid', 'site-connection-back-to-sites');
    });

    it('does not manufacture the source not-found action for generic server failure', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(json({ message: 'Unavailable.' }, 500))
            .mockResolvedValueOnce(json({ items: [] }, 200)));

        renderControl();

        expect(await screen.findByRole('alert')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Back to sites' })).not.toBeInTheDocument();
    });

    it('does not show the not-found action after an authoritative site is loaded', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(json({
                site: { id: 7, name: 'Alpha Site', connection_status: 'connected' },
                connection: 'CONNECTED',
            }, 200))
            .mockResolvedValueOnce(json({ items: [] }, 200)));

        renderControl();

        expect(await screen.findByText('Alpha Site')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Back to sites' })).not.toBeInTheDocument();
    });

    it('derives the destination only from the active tenant context', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(json({ message: 'Site not found.' }, 404))
            .mockResolvedValueOnce(json({ items: [] }, 200)));

        renderControl(context('tenant one'));

        expect(await screen.findByRole('link', { name: 'Back to sites' }))
            .toHaveAttribute('href', '/tenants/tenant%20one/sites');
    });
});
