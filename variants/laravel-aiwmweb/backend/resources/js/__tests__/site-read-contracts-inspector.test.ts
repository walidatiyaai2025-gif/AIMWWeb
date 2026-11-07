import { describe, expect, it } from 'vitest';
import { resolveCapability, workspaceRoutes, type FrontendContext } from '../core';
import { workspaceCapabilityCensus } from '../workspace-capability-census';
import { liveAuditPlan, safeAuditEndpoint } from '../runtime-workspace-audit';

function base(): FrontendContext {
    return {
        user: { id: 1, name: 'A', email: 'a@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.manage', 'sites.view'],
        capabilities: {},
        connectors: [],
        api: { sites: '/api/tenants/alpha/sites' },
        actions: {},
    };
}

describe('site-scoped inspector contracts use authoritative existing JSON GETs', () => {
    it('allows opening the onboarding page without any pre-existing paired connector', () => {
        const context = base();
        const route = workspaceRoutes.find(item => item.key === 'site-connect')!;
        expect(route.apiKey).toBeUndefined();
        expect(route.connectorScope).toBeUndefined();
        expect(resolveCapability(context, route).state).toBe('enabled');
        // A view permission never authorizes the separately controlled onboarding POST.
        expect(resolveCapability(context, route, 'sites.connect').state).toBe('pending_integration');

        const census = workspaceCapabilityCensus(context).workspaces.find(item => item.key === 'site-connect');
        expect(census).toMatchObject({
            view_state: 'declared_accessible',
            connector_read: 'not_required',
            api_contract: 'not_required',
        });
        const audit = liveAuditPlan(context).find(item => item.key === 'site-connect');
        expect(audit).toMatchObject({ state: 'not_tested', reason: 'navigation_only', tested_write_actions: 0 });
    });

    it('does not guess site-specific read URLs when no authorized site was selected', () => {
        const context = base();
        for (const key of ['site-details', 'site-snapshot', 'site-offline-data',
            'site-connection-center', 'site-connection-operations']) {
            const entry = liveAuditPlan(context).find(item => item.key === key)!;
            expect(entry.state, key).toBe('skipped');
            expect(entry.reason, key).toBe('missing_contract');
        }
    });

    it('audits each exact selected-site read without any mutation or cross-tenant target', () => {
        const context: FrontendContext = {
            ...base(),
            active_site: { id: 7, name: 'Selected', status: 'active' },
            api: {
                ...base().api,
                'site-details': '/api/tenants/alpha/sites/7',
                'site-snapshot': '/api/tenants/alpha/sites/7/snapshot',
                'site-offline-data': '/api/tenants/alpha/sites/7/snapshot',
                'site-connection-center': '/api/tenants/alpha/sites/7/connection',
                'site-connection-operations': '/api/tenants/alpha/sites/7/operations',
            },
        };
        const keys = ['site-details', 'site-snapshot', 'site-offline-data',
            'site-connection-center', 'site-connection-operations'];
        const plan = liveAuditPlan(context);
        const census = workspaceCapabilityCensus(context);
        for (const key of keys) {
            const route = workspaceRoutes.find(item => item.key === key)!;
            const endpoint = context.api[route.apiKey!];
            expect(safeAuditEndpoint(endpoint, 'alpha'), key).toBe(true);
            expect(safeAuditEndpoint(endpoint, 'beta'), key).toBe(false);
            expect(plan.find(item => item.key === key)).toMatchObject({
                state: 'not_tested', reason: 'read_ok', tested_write_actions: 0,
            });
            expect(census.workspaces.find(item => item.key === key)?.view_state)
                .toBe('declared_accessible');
        }
        expect(plan.every(row => row.tested_write_actions === 0)).toBe(true);
        expect(plan.reduce((n, row) => n + row.declared_write_actions, 0)).toBe(46);
    });
});
