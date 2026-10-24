import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';
import {
    SITE_RELIABILITY_CONNECTION_LINK_OPERATION_ID,
    SiteReliabilityConnectionLinkControl,
    siteReliabilityConnectionDestination,
} from '../site-reliability-connection-link-control';

function context(slug = 'alpha team'): FrontendContext {
    return {
        user: { id: 1, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug, name: 'Alpha Team' },
        tenants: [{ slug, name: 'Alpha Team' }],
        permissions: ['tenant.view', 'sites.view'],
        connectors: [],
        capabilities: {},
        api: {
            'site-reliability': `/tenants/${encodeURIComponent(slug)}/route-api/site-reliability`,
        },
        actions: {},
    };
}

const route: WorkspaceRoute = {
    key: 'site-reliability',
    path: '/site-reliability',
    group: 'operations',
    icon: '◒',
    label: { en: 'Site Reliability', ar: 'موثوقية المواقع' },
    description: { en: 'Compare connectivity and synchronization reliability.', ar: 'مقارنة موثوقية الاتصال والمزامنة.' },
    permission: 'sites.view',
    controls: [],
    kind: 'resource',
};

function renderWorkspace(slug = 'alpha team') {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <MemoryRouter>
            <QueryClientProvider client={client}>
                <LocaleProvider>
                    <ToastProvider>
                        <WorkspacePage context={context(slug)} route={route} />
                    </ToastProvider>
                </LocaleProvider>
            </QueryClientProvider>
        </MemoryRouter>,
    );
}

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    window.localStorage.clear();
});

describe(`${SITE_RELIABILITY_CONNECTION_LINK_OPERATION_ID} Site Reliability connection navigation`, () => {
    it('renders Open from the authoritative reliability row site_id without an extra request', async () => {
        const fetchMock = vi.fn().mockResolvedValueOnce(new Response(JSON.stringify({
            data: [{
                site_id: 11,
                site_name: 'Alpha Reliability Site',
                total_operations: 4,
                successful: 3,
                failed: 1,
                success_rate: 75,
                last_failure_at: '2026-10-24T10:00:00Z',
                retained_history_only: true,
            }],
            meta: { period_days: 30, retained_history_limit: 2000, live_health_monitor: false },
        }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        const open = await screen.findByRole('link', { name: 'Open' });
        expect(open).toHaveAttribute('href', '/tenants/alpha%20team/sites/11/connection');
        expect(open).toHaveAttribute('data-canonical-operation', SITE_RELIABILITY_CONNECTION_LINK_OPERATION_ID);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls.every(([, options]) => !options || options.method === undefined || options.method === 'GET')).toBe(true);
    });

    it('fails closed for missing or malformed row site ids', () => {
        for (const siteId of [null, undefined, 0, -1, 1.5, '0', '01', ' 7', '7 ', 'abc', '../7']) {
            const { unmount } = render(
                <MemoryRouter>
                    <LocaleProvider>
                        <SiteReliabilityConnectionLinkControl tenantSlug="alpha" siteId={siteId} />
                    </LocaleProvider>
                </MemoryRouter>,
            );
            expect(screen.queryByRole('link')).not.toBeInTheDocument();
            unmount();
        }
    });

    it('derives the destination only from the active tenant slug and positive authoritative site id', () => {
        expect(siteReliabilityConnectionDestination('alpha team', 42))
            .toBe('/tenants/alpha%20team/sites/42/connection');
        expect(siteReliabilityConnectionDestination('alpha/../beta', 42))
            .toBe('/tenants/alpha%2F..%2Fbeta/sites/42/connection');
        expect(siteReliabilityConnectionDestination('', 42)).toBeNull();
        expect(siteReliabilityConnectionDestination('alpha', '0042')).toBeNull();
    });

    it('preserves the Arabic source label on the same tenant-scoped destination', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <SiteReliabilityConnectionLinkControl tenantSlug="alpha" siteId={7} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        const open = screen.getByRole('link', { name: 'فتح' });
        expect(open).toHaveAttribute('href', '/tenants/alpha/sites/7/connection');
        expect(open).toHaveAttribute('data-canonical-operation', 'AIMW-CONT-A93DE991C0');
    });
});
