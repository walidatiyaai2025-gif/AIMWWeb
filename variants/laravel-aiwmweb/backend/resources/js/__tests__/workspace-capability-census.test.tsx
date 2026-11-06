import React from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { workspaceRoutes } from '../core';
import { LocaleProvider } from '../i18n';
import { safeInspectorBundle, type InspectorServer } from '../runtime-inspector-panel';
import { workspaceCapabilityCensus } from '../workspace-capability-census';
import { WorkspaceCapabilityCensusPanel } from '../workspace-capability-census-panel';

function ctx(): FrontendContext {
    return {
        user: { id: 25, name: 'PRIVATE USER NAME', email: 'PRIVATE-EMAIL@example.test' },
        tenant: { slug: 'PRIVATE-TENANT', name: 'PRIVATE TENANT NAME' },
        tenants: [{ slug: 'PRIVATE-TENANT', name: 'PRIVATE TENANT NAME' }],
        permissions: ['tenant.view', 'content.view', 'diagnostics.view'],
        connectors: [{ key: 'PRIVATE-CONNECTOR-IDENTITY', state: 'connected', scopes: ['content.read'] }],
        capabilities: {},
        api: { pages: '/api/tenants/PRIVATE-TENANT?token=PRIVATE-TOKEN' },
        actions: {},
    };
}

const server: InspectorServer = {
    schema: 'aiwm.runtime-inspector.v1',
    generated_at: '2026-11-06T08:00:00+00:00',
    safety: { redacted: true, read_only: true },
    release: { version: '1.0.292', sha: '1126d85d46a136f4ea962fd0ac0ee6c868179c14' },
    runtime: {
        php_version: '8.4', laravel_version: '13', database: 'reachable',
        database_driver: 'mysql', queue_driver: 'database', cache_driver: 'database',
        extensions: { sodium: true, mbstring: true, zip: true },
    },
    site_count: 0, sites_truncated: false, sites: [],
};

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('complete declarative workspace census', () => {
    it('audits every route and flags the missing ai.configure permission without inventing a backend error', () => {
        const census = workspaceCapabilityCensus(ctx());
        expect(census.totals.routes).toBe(workspaceRoutes.length);
        expect(census.totals.routes).toBeGreaterThan(50);
        expect(census.workspaces.find(item => item.key === 'ai-providers')).toMatchObject({
            required_permission: 'ai.configure', permission_present: false, view_state: 'missing_permission',
        });
        const pages = census.workspaces.find(item => item.key === 'pages');
        expect(pages).toMatchObject({ connector_read: 'content_read_alias', permission_present: true });
        expect(census.safety).toEqual({
            read_only: true, endpoint_probes: false, no_write_authorization_inferred: true,
        });
        expect(census.totals.routes).toBe(Object.values(census.totals).slice(1, 6)
            .reduce((total, n) => total + n, 0));
    });

    it('never treats a connected content.read scope as permission to publish or mutate', () => {
        const context = ctx();
        context.actions = {
            'pages.publish': {
                endpoint: '/api/tenants/PRIVATE-TENANT/pages/22?key=PRIVATE-TOKEN',
                method: 'POST',
            },
        };
        const pages = workspaceCapabilityCensus(context).workspaces.find(item => item.key === 'pages')!;
        expect(pages.connector_read).toBe('content_read_alias');
        expect(pages.write_controls_declared).toBeGreaterThan(0);
        expect(pages.write_controls_advertised).toBe(1);
        expect(pages.backend_capability).toBe('not_reported');
        expect(pages.view_state).toBe('declared_accessible');
    });

    it('exports strictly enumerated route state, not user, tenant, secrets, URLs or connector identities', () => {
        const context = ctx();
        const report = safeInspectorBundle(server, [], context);
        expect(report.workspace_census?.workspaces).toHaveLength(workspaceRoutes.length);
        const serialized = JSON.stringify(report);
        for (const sensitive of [
            'PRIVATE USER NAME', 'PRIVATE-EMAIL', 'PRIVATE-TENANT', 'PRIVATE TENANT NAME',
            'PRIVATE-CONNECTOR-IDENTITY', 'PRIVATE-TOKEN',
        ]) {
            expect(serialized).not.toContain(sensitive);
        }
        expect(serialized).toContain('missing_permission');
        expect(serialized).not.toContain('api/tenants');
    });

    it('renders a complete zero-network audit, with findings default and optional all routes', () => {
        const fetch = vi.fn();
        vi.stubGlobal('fetch', fetch);
        const ui = render(<LocaleProvider><WorkspaceCapabilityCensusPanel context={ctx()} /></LocaleProvider>);
        expect(screen.getByText('All-workspace capability census')).toBeInTheDocument();
        expect(fetch).not.toHaveBeenCalled();
        fireEvent.click(screen.getByText('Detailed workspace findings'));
        expect(screen.getAllByText('Tenant permission missing').length).toBeGreaterThan(0);
        fireEvent.click(screen.getByRole('button', { name: 'All workspaces' }));
        expect(screen.getAllByRole('row').length).toBeGreaterThan(50);
        expect(fetch).not.toHaveBeenCalled();
        ui.unmount();
    });
});
