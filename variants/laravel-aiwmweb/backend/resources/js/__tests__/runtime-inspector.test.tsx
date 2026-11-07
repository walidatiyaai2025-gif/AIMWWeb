import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    clearRuntimeInspectorEvents,
    diagnosticRouteShape,
    recordApiObservation,
    runtimeInspectorEvents,
} from '../runtime-inspector-capture';
import { RuntimeInspectorPanel, safeInspectorBundle, type InspectorServer } from '../runtime-inspector-panel';

const ctx = (permissions = ['tenant.view', 'diagnostics.view']): FrontendContext => ({
    user: { id: 7, name: 'Private owner', email: 'owner@canary.example.test' },
    tenant: { slug: 'wordpress', name: 'Secret WordPress Tenant' },
    tenants: [{ slug: 'wordpress', name: 'Secret WordPress Tenant' }],
    permissions,
    connectors: [{ key: 'canary-private-key', state: 'connected', scopes: ['content.read'] }],
    capabilities: {},
    api: {},
    actions: {},
});

const server: InspectorServer = {
    schema: 'aiwm.runtime-inspector.v1',
    generated_at: '2026-11-05T20:00:00+00:00',
    safety: { redacted: true, read_only: true },
    release: { version: '1.0.267', sha: '8cdccdc45cd64e0358d0098ed47128b4e2989599' },
    runtime: {
        php_version: '8.4',
        laravel_version: '12.0',
        database: 'reachable',
        database_driver: 'mysql',
        queue_driver: 'database',
        cache_driver: 'file',
        extensions: { pdo_mysql: true, openssl: true, sodium: true, mbstring: true, zip: true },
    },
    site_count: 1,
    sites_truncated: false,
    sites: [{
        site_id: 1,
        connection_state: 'paired',
        health_state: 'unknown',
        connector: {
            present: true,
            revoked: false,
            verified: true,
            protocol: '1',
            enabled_scopes: ['health', 'content.read'],
        },
        last_diagnostic: 'not_run',
        last_sync: { present: false, state: 'not_run', finished: false },
        record_counts: { posts: 0, pages: 0, media: 0, legacy_synced_contents: 0 },
        finding: 'CANONICAL_SYNC_NOT_STARTED',
    }],
};

function mounted(context = ctx()) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <MemoryRouter initialEntries={['/tenants/wordpress/runtime-inspector']}>
                <LocaleProvider><RuntimeInspectorPanel context={context} /></LocaleProvider>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    cleanup();
    clearRuntimeInspectorEvents();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('runtime inspector safe diagnostics', () => {
    it('redacts original URLs including tokens and tenant identifiers and limits memory to 120 observations', () => {
        const path = diagnosticRouteShape('/api/v1/tenants/secret-wp/sites/987/sync/runs/32?token=PRIVATE_API_KEY');
        expect(path).toBe('/api/v1/tenants/:id/sites/:id/sync/runs/:id');
        expect(path).not.toContain('secret-wp');
        expect(path).not.toContain('PRIVATE_API_KEY');

        for (let i = 0; i < 200; i++) {
            recordApiObservation('/api/v1/tenants/secret-wp/sites/987/content/post?api_key=CANARY_SECRET', 'GET', 403, i);
        }
        const observations = runtimeInspectorEvents();
        expect(observations).toHaveLength(120);
        expect(JSON.stringify(observations)).not.toContain('CANARY_SECRET');
        expect(JSON.stringify(observations)).not.toContain('987');
        expect(observations[0]).toMatchObject({ status: 403, kind: 'api', method: 'GET' });
        recordApiObservation('https://wp.example.test/?password=CANARY_PASSWORD', 'POST', null, 100);
        expect(runtimeInspectorEvents().at(-1)?.kind).toBe('network_failure');
        expect(JSON.stringify(runtimeInspectorEvents())).not.toContain('CANARY_PASSWORD');
    });

    it('exports only an explicitly allowlisted schema without personal or secret payload data', () => {
        const unsafe = {
            ...server,
            secret: 'EXTRA_SECRET_CANARY',
            sites: [{
                ...server.sites[0],
                site_url: 'https://private.test/?token=SECRET_URL',
                connector: { ...server.sites[0].connector, encrypted_secret: 'CONNECTOR_SECRET_CANARY' },
                post_title: 'PRIVATE_POST_CANARY',
            }],
            runtime: { ...server.runtime, database_password: 'DATABASE_SECRET_CANARY' },
        };
        recordApiObservation('/api/v1/tenants/wordpress/sites/1/connector?auth=SECRET_QUERY', 'GET', 500, 42);
        const bundle = safeInspectorBundle(unsafe, runtimeInspectorEvents());
        const raw = JSON.stringify(bundle);

        expect(bundle.schema).toBe('aiwm.support-export.v1');
        expect(bundle.server.sites[0].finding).toBe('CANONICAL_SYNC_NOT_STARTED');
        expect(bundle.browser.captured_events[0]).toMatchObject({ status: 500, duration_ms: 42 });
        for (const secret of ['EXTRA_SECRET_CANARY', 'SECRET_URL', 'CONNECTOR_SECRET_CANARY',
            'PRIVATE_POST_CANARY', 'DATABASE_SECRET_CANARY', 'SECRET_QUERY', 'owner@canary.example.test']) {
            expect(raw).not.toContain(secret);
        }
        expect(raw).toContain('8cdccdc45cd64e0358d0098ed47128b4e2989599');
    });

    it('rejects malformed exported timestamps and never leaks unexpected event fields', () => {
        const injected = {
            ...server,
            generated_at: '2026-11-07TSECRET_SERVER_TIMESTAMP_CANARY',
        };
        const maliciousEvents = [
            {
                at: '2026-11-07TSECRET_EVENT_TIMESTAMP_CANARY',
                kind: 'api', method: 'GET', route: '/api/v1/tenants/wordpress/sites/1?token=SECRET',
            },
            {
                at: '2026-11-07T12:15:00+03:00',
                kind: 'CANARY_KIND_SECRET', method: 'POST',
            },
            {
                at: '2026-11-07T12:15:00+03:00',
                kind: 'api', method: 'CANARY_METHOD_SECRET',
            },
            {
                at: '2026-11-07T09:15:00.123Z',
                kind: 'resource_error', method: 'OTHER',
            },
        ] as unknown as Parameters<typeof safeInspectorBundle>[1];

        const result = safeInspectorBundle(injected, maliciousEvents);
        const exported = JSON.stringify(result);
        expect(result.server.generated_at).toBe('unknown');
        expect(result.browser.captured_events).toHaveLength(3);
        expect(result.browser.captured_events[0]).toMatchObject({
            at: 'unknown', kind: 'api', method: 'GET',
            route: '/api/v1/tenants/:id/sites/:id',
        });
        expect(result.browser.captured_events[1]).toMatchObject({
            at: '2026-11-07T09:15:00.000Z', kind: 'api',
        });
        expect(result.browser.captured_events[1]).not.toHaveProperty('method');
        expect(result.browser.captured_events[2]).toMatchObject({
            at: '2026-11-07T09:15:00.123Z', kind: 'resource_error', method: 'OTHER',
        });
        for (const secret of ['SECRET_SERVER_TIMESTAMP_CANARY', 'SECRET_EVENT_TIMESTAMP_CANARY',
            'CANARY_KIND_SECRET', 'CANARY_METHOD_SECRET', 'token=SECRET']) {
            expect(exported).not.toContain(secret);
        }
    });

    it('normalizes valid timezone timestamps rather than copying untrusted raw text', () => {
        const report = safeInspectorBundle({
            ...server,
            generated_at: '2026-11-07T13:45:00+03:00',
        }, []);
        expect(report.server.generated_at).toBe('2026-11-07T10:45:00.000Z');
        for (const invalid of ['2026-11-07TAPI_KEY', '2026-13-07T00:00:00Z',
            '2026-11-07T25:00:00Z', '2026-11-07T12:00:00']) {
            const result = safeInspectorBundle({ ...server, generated_at: invalid }, []);
            expect(result.server.generated_at).toBe('unknown');
        }
    });

    it('does not fetch diagnostics for unauthorized tenant members', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        mounted(ctx(['tenant.view']));
        expect(screen.getByRole('alert')).toHaveTextContent('diagnostics.view permission is required');
        await waitFor(() => expect(fetchMock).not.toHaveBeenCalled());
    });

    it('shows separate unverified and unsupported Connector readiness findings without claiming sync success', async () => {
        const response = {
            ...server,
            sites: [
                {
                    ...server.sites[0],
                    connector: { ...server.sites[0].connector, verified: false },
                    finding: 'CONNECTOR_NOT_VERIFIED',
                },
                {
                    ...server.sites[0],
                    site_id: 2,
                    connector: { ...server.sites[0].connector, verified: true },
                    finding: 'CONTENT_READ_UNSUPPORTED',
                },
            ],
        };
        vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify(response), {
            status: 200, headers: { 'content-type': 'application/json' },
        })));

        mounted();
        fireEvent.click(screen.getByRole('button', { name: 'Run Inspector' }));

        expect(await screen.findByText('Connector pairing has not been verified')).toBeInTheDocument();
        expect(screen.getByText('Connector does not advertise content.read')).toBeInTheDocument();
        expect(screen.queryByText('Canonical content records exist')).not.toBeInTheDocument();
    });

    it('audits the only listed site with a separate GET context without changing the dashboard selection', async () => {
        const context = ctx(['tenant.view', 'diagnostics.view', 'content.view']);
        const scoped: FrontendContext = {
            ...context,
            active_site: { id: 1, name: 'PRIVATE-SITE', status: 'connected' },
            api: { dashboard: '/api/dashboard', posts: '/api/v1/tenants/wordpress/sites/1/content/post' },
        };
        const requested: string[] = [];
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const endpoint = String(input);
            requested.push(endpoint);
            if (endpoint.includes('/runtime-inspector/report')) {
                return new Response(JSON.stringify(server), {
                    headers: { 'content-type': 'application/json' },
                });
            }
            if (endpoint === '/tenants/wordpress/context?site=1') {
                expect(init?.method).toBe('GET');
                return new Response(JSON.stringify(scoped), {
                    headers: { 'content-type': 'application/json' },
                });
            }
            expect(init?.method).toBe('GET');
            return new Response('{"items":[]}', {
                headers: { 'content-type': 'application/json' },
            });
        }));

        mounted(context);
        fireEvent.click(screen.getByRole('button', { name: 'Run Inspector' }));
        await screen.findByRole('option', { name: 'Site #1' });
        fireEvent.click(screen.getByRole('button', { name: 'Scan safe functions across all pages' }));
        await waitFor(() => expect(requested).toContain('/tenants/wordpress/context?site=1'));
        await waitFor(() => expect(screen.getByText('GET passed: 2')).toBeInTheDocument());
        expect(requested).toContain('/api/v1/tenants/wordpress/sites/1/content/post');
        expect(requested.some(endpoint => /\\b(?:publish|delete|sync)\\b/.test(endpoint))).toBe(false);
    });

    it('requires explicit audit-site choice even when a different dashboard site is active', async () => {
        const context: FrontendContext = {
            ...ctx(),
            active_site: { id: 1, name: 'DASHBOARD-SITE', status: 'connected' },
        };
        const twoSites = {
            ...server, site_count: 2,
            sites: [...server.sites, { ...server.sites[0], site_id: 2 }],
        };
        const scoped: FrontendContext = {
            ...context, active_site: { id: 2, name: 'PRIVATE-SECOND' },
            api: { dashboard: '/api/dashboard' },
        };
        const calls: string[] = [];
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const endpoint = String(input);
            calls.push(endpoint);
            expect(init?.method ?? 'GET').toBe('GET');
            return new Response(JSON.stringify(endpoint.includes('/runtime-inspector/report')
                ? twoSites : endpoint.includes('/context?site=2') ? scoped : { items: [] }), {
                status: 200, headers: { 'content-type': 'application/json' },
            });
        }));
        mounted(context);
        fireEvent.click(screen.getByRole('button', { name: 'Run Inspector' }));
        const siteSelect = await screen.findByRole('combobox', { name: 'Select audit site' });
        const scan = screen.getByRole('button', { name: 'Scan safe functions across all pages' });
        expect(scan).toBeDisabled();
        fireEvent.change(siteSelect, { target: { value: '2' } });
        expect(scan).toBeEnabled();
        fireEvent.click(scan);
        await waitFor(() => expect(calls).toContain('/tenants/wordpress/context?site=2'));
        expect(calls).not.toContain('/tenants/wordpress/context?site=1');
        expect(context.active_site?.id).toBe(1);
    });

    it('runs an explicit safe GET and permits export only after a successful report', async () => {
        const fetchMock = vi.fn(async (_input: RequestInfo | URL) => new Response(JSON.stringify(server), {
            status: 200, headers: { 'content-type': 'application/json' },
        }));
        vi.stubGlobal('fetch', fetchMock);
        const createObjectURL = vi.fn(() => 'blob:https://localhost/inspector');
        const revokeObjectURL = vi.fn();
        vi.stubGlobal('URL', Object.assign(URL, { createObjectURL, revokeObjectURL }));
        const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});

        mounted();
        const exportButton = screen.getByRole('button', { name: 'Export sanitized JSON' });
        expect(exportButton).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Run Inspector' }));

        expect(await screen.findByText(/Canonical content sync not started/)).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/tenants/wordpress/runtime-inspector/report?probe=1');
        expect(exportButton).toBeEnabled();
        fireEvent.click(exportButton);
        expect(click).toHaveBeenCalled();
        expect(createObjectURL).toHaveBeenCalled();
        expect(revokeObjectURL).toHaveBeenCalledWith('blob:https://localhost/inspector');
    });
});
