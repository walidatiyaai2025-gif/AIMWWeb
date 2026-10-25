import React from 'react';
import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { OperationsHubReloadControl, OPERATIONS_HUB_RELOAD_OPERATION_ID } from '../operations-hub-reload-control';
import { OperationsHubSitesLinkControl, OPERATIONS_HUB_SITES_LINK_OPERATION_ID } from '../operations-hub-sites-link-control';

function context(slug = 'alpha'): FrontendContext {
    return {
        user: { id: 1, name: 'Operator', email: 'operator@example.test' },
        tenant: { slug, name: 'Active tenant' }, tenants: [{ slug, name: 'Active tenant' }],
        permissions: ['tenant.view', 'sites.view', 'execution.view', 'operations.manage'],
        connectors: [], capabilities: {}, actions: {},
        api: { 'operations-hub': `/tenants/${encodeURIComponent(slug)}/admin/operations-hub` },
    };
}

afterEach(() => { vi.unstubAllGlobals(); localStorage.clear(); });

describe('AIMW-CONT-7EF5524637 Operations Hub Manage sites', () => {
    it('uses the active tenant, encodes its slug and preserves Arabic text', () => {
        localStorage.setItem('aiwm.locale', 'ar');
        render(<LocaleProvider><OperationsHubSitesLinkControl context={context('tenant with space')} /></LocaleProvider>);
        const link = screen.getByRole('link', { name: 'إدارة المواقع' });
        expect(link).toHaveAttribute('href', '/tenants/tenant%20with%20space/sites');
        expect(link).toHaveAttribute('data-canonical-operation', OPERATIONS_HUB_SITES_LINK_OPERATION_ID);
        expect(OPERATIONS_HUB_SITES_LINK_OPERATION_ID).toBe('AIMW-CONT-7EF5524637');
    });

    it.each(['tenant.view', 'sites.view', 'execution.view'])('hides the link without %s', (permission) => {
        const value = context();
        value.permissions = value.permissions.filter((p) => p !== permission);
        render(<LocaleProvider><OperationsHubSitesLinkControl context={value} /></LocaleProvider>);
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('does not expose the link while the authoritative read is loading', () => {
        vi.stubGlobal('fetch', vi.fn(() => new Promise(() => {})));
        render(<LocaleProvider><OperationsHubReloadControl context={context()} /></LocaleProvider>);
        expect(screen.getByTestId('operations-hub-loading')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Manage sites' })).not.toBeInTheDocument();
    });

    it('offers real navigation beside retry after a failed read', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ message: 'Unavailable' }), { status: 503, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);
        render(<LocaleProvider><OperationsHubReloadControl context={context()} /></LocaleProvider>);
        expect(await screen.findByRole('link', { name: 'Manage sites' })).toHaveAttribute('href', '/tenants/alpha/sites');
        expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it.each([{ operations: [] }, { operations: [{ id: 1, type: 'sync', status: 'succeeded' }] }])('offers real navigation after an empty or populated read', async ({ operations }) => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            data: { sites: [], operations },
            meta: { operation_id: OPERATIONS_HUB_RELOAD_OPERATION_ID, tenant: 'alpha', refreshed_at: '2026-10-25' },
        }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);
        render(<LocaleProvider><OperationsHubReloadControl context={context()} /></LocaleProvider>);
        expect(await screen.findByRole('link', { name: 'Manage sites' })).toHaveAttribute('href', '/tenants/alpha/sites');
        expect(screen.getByTestId('operations-hub-live-snapshot')).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const options = fetchMock.mock.calls[0][1];
        expect(options?.method ?? 'GET').toBe('GET');
    });
});
