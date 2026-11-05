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
            verified: false,
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

    it('does not fetch diagnostics for unauthorized tenant members', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        mounted(ctx(['tenant.view']));
        expect(screen.getByRole('alert')).toHaveTextContent('diagnostics.view permission is required');
        await waitFor(() => expect(fetchMock).not.toHaveBeenCalled());
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
        expect(fetchMock.mock.calls[0][0]).toBe('/tenants/wordpress/runtime-inspector/report');
        expect(exportButton).toBeEnabled();
        fireEvent.click(exportButton);
        expect(click).toHaveBeenCalled();
        expect(createObjectURL).toHaveBeenCalled();
        expect(revokeObjectURL).toHaveBeenCalledWith('blob:https://localhost/inspector');
    });
});
