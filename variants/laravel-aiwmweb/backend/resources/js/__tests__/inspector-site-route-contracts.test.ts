import { describe, expect, it } from 'vitest';
import { workspaceRoutes, type FrontendContext } from '../core';
import { workspaceCapabilityCensus } from '../workspace-capability-census';
import { liveAuditPlan, safeAuditEndpoint } from '../runtime-workspace-audit';

const readRoutes = {
    'site-details': '/api/tenants/alpha/sites/7',
    'site-snapshot': '/api/tenants/alpha/sites/7/snapshot',
    'site-offline-data': '/api/tenants/alpha/sites/7/snapshot',
    'site-connection-center': '/api/tenants/alpha/sites/7/connection',
    'site-connection-operations': '/api/tenants/alpha/sites/7/operations',
};

const context = (selected: boolean): FrontendContext => ({
    user: { id: 1, name: 'private-user', email: 'private@example.test' },
    tenant: { slug: 'alpha', name: 'private-tenant' },
    tenants: [{ slug: 'alpha', name: 'private-tenant' }],
    permissions: ['*'],
    active_site: selected ? { id: 7, name: 'private-site' } : null,
    api: selected ? {
        ...readRoutes,
        'sites.detail.7': '/api/tenants/alpha/sites/7',
    } : {},
    actions: {},
    connectors: [],
    capabilities: {},
});

describe('site-specific read aliases in Runtime Inspector', () => {
    it('keeps the six hidden site workspaces truthful before a site is selected', () => {
        const routes = workspaceRoutes.filter(route => route.key === 'site-connect' ||
            Object.prototype.hasOwnProperty.call(readRoutes, route.key));
        expect(routes).toHaveLength(6);
        expect(routes.find(route => route.key === 'site-connect')?.apiKey).toBeUndefined();
        const missing = workspaceCapabilityCensus(context(false));
        for (const key of Object.keys(readRoutes)) {
            expect(missing.workspaces.find(item => item.key === key)?.api_contract).toBe('not_advertised');
        }
        expect(liveAuditPlan(context(false)).find(item => item.key === 'site-connect')?.reason)
            .toBe('navigation_only');
        expect(missing.totals.routes).toBe(58);
        expect(missing.totals.write_controls_declared).toBe(46);
    });

    it('allows only real same-tenant GET aliases without treating them as write capability', () => {
        const selected = context(true);
        const census = workspaceCapabilityCensus(selected);
        const audit = liveAuditPlan(selected);
        for (const [key, endpoint] of Object.entries(readRoutes)) {
            expect(selected.api[key], key).toBe(endpoint);
            expect(safeAuditEndpoint(endpoint, 'alpha')).toBe(true);
            expect(safeAuditEndpoint(endpoint.replace('/alpha/', '/foreign/'), 'alpha')).toBe(false);
            expect(census.workspaces.find(item => item.key === key)?.api_contract).toBe('advertised');
            expect(audit.find(item => item.key === key)?.reason).toBe('read_ok');
            expect(audit.find(item => item.key === key)?.tested_write_actions).toBe(0);
        }
        expect(census.totals.write_controls_declared).toBe(46);
        expect(audit.every(item => item.tested_write_actions === 0)).toBe(true);
        // The backend advertises read URLs only; the scan never follows write controls.
        expect(census.workspaces.find(item => item.key === 'site-connect')?.write_controls_declared).toBe(1);
    });
});
