import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITE_CONNECTION_CENTER_DYNAMIC_LINKS_OPERATION_ID,
    SiteConnectionCenterSyncControl,
    siteConnectionCenterDynamicLinks,
} from '../site-connection-center-sync-control';

function context(slug = 'alpha'): FrontendContext {
    return {
        user: { id: 7, name: 'Operator', email: 'operator@example.test' },
        tenant: { slug, name: slug },
        tenants: [{ slug, name: slug }],
        permissions: ['tenant.view', 'sites.view', 'execution.view'],
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
    };
}

function json(payload: unknown, status = 200) {
    return new Response(JSON.stringify(payload), {
        status,
        headers: { 'content-type': 'application/json' },
    });
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

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('AIMW-CONT-DF6FFA9D87 Site Connection Center dynamic links', () => {
    it('derives all source dynamic destinations from the active tenant and authoritative site', () => {
        const links = siteConnectionCenterDynamicLinks('alpha', 7, {
            id: 7,
            name: 'Alpha Site',
            url: 'https://wordpress.example.test/subdir/',
        });

        expect(SITE_CONNECTION_CENTER_DYNAMIC_LINKS_OPERATION_ID).toBe('AIMW-CONT-DF6FFA9D87');
        expect(links).toEqual({
            dashboard: '/tenants/alpha/sites/7',
            applicationPassword: 'https://wordpress.example.test/subdir/wp-admin/profile.php',
            localContent: '/tenants/alpha/explorer?site=7',
            execution: '/tenants/alpha/module/execution?siteId=7',
        });
    });

    it('fails closed when route identity does not match the authoritative site', () => {
        expect(siteConnectionCenterDynamicLinks('alpha', 8, {
            id: 7,
            name: 'Alpha Site',
            url: 'https://wordpress.example.test',
        })).toBeNull();

        expect(siteConnectionCenterDynamicLinks('alpha/beta', 7, {
            id: 7,
            name: 'Alpha Site',
            url: 'https://wordpress.example.test',
        })).toBeNull();
    });

    it('keeps internal links but suppresses unsafe external Application Password destination', () => {
        const links = siteConnectionCenterDynamicLinks('alpha', 7, {
            id: 7,
            name: 'Alpha Site',
            url: 'javascript:alert(1)',
        });

        expect(links?.applicationPassword).toBeNull();
        expect(links?.dashboard).toBe('/tenants/alpha/sites/7');
        expect(links?.localContent).toBe('/tenants/alpha/explorer?site=7');
        expect(links?.execution).toBe('/tenants/alpha/module/execution?siteId=7');
    });

    it('renders the complete navigation only after authoritative connection state matches the route site', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(json({
                site: {
                    id: 7,
                    name: 'Alpha Site',
                    url: 'https://wordpress.example.test',
                    connection_status: 'connected',
                },
                connection: 'CONNECTED',
            }))
            .mockResolvedValueOnce(json({ items: [] })));

        renderControl();

        expect(await screen.findByText('Alpha Site')).toBeInTheDocument();
        const section = screen.getByTestId('site-connection-dynamic-links');
        expect(section).toHaveAttribute(
            'data-canonical-operation',
            SITE_CONNECTION_CENTER_DYNAMIC_LINKS_OPERATION_ID,
        );
        expect(screen.getByRole('link', { name: 'Site dashboard' })).toHaveAttribute('href', '/tenants/alpha/sites/7');
        expect(screen.getByRole('link', { name: 'Application Password' }))
            .toHaveAttribute('href', 'https://wordpress.example.test/wp-admin/profile.php');
        expect(screen.getByRole('link', { name: 'Local content' })).toHaveAttribute('href', '/tenants/alpha/explorer?site=7');
        expect(screen.getByRole('link', { name: 'Execution Center' }))
            .toHaveAttribute('href', '/tenants/alpha/module/execution?siteId=7');
    });

    it('does not render dynamic navigation for authoritative read failure', async () => {
        vi.stubGlobal('fetch', vi.fn()
            .mockResolvedValueOnce(json({ message: 'Site not found.' }, 404))
            .mockResolvedValueOnce(json({ items: [] })));

        renderControl();

        expect(await screen.findByRole('alert')).toBeInTheDocument();
        expect(screen.queryByTestId('site-connection-dynamic-links')).not.toBeInTheDocument();
    });
});
