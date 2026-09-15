import { describe, expect, it } from 'vitest';
import {
    canOpenDashboardExecution,
    dashboardExecutionHref,
    DASHBOARD_EXECUTION_LINK_OPERATION_ID,
} from '../dashboard-execution-link-control';
import { workspaceRoutes, type FrontendContext } from '../core';

const context = (permissions: string[] = ['tenant.view', 'execution.view', 'operations.manage']): FrontendContext => ({
    user: { id: 1, name: 'Operator', email: 'operator@example.test' },
    tenant: { slug: 'alpha workspace', name: 'Alpha' },
    tenants: [{ slug: 'alpha workspace', name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

describe('canonical Home execution visible control', () => {
    it('targets the active tenant execution workspace with the exact canonical operation identity', () => {
        const executionRoute = workspaceRoutes.find((route) => route.key === 'execution');

        expect(DASHBOARD_EXECUTION_LINK_OPERATION_ID).toBe('AIMW-AUTO-151800BD9D');
        expect(executionRoute?.path).toBe('/module/execution');
        expect(executionRoute?.permission).toBe('execution.view');
        expect(dashboardExecutionHref(context())).toBe('/tenants/alpha%20workspace/module/execution');
    });

    it('never emits the unqualified source path or a foreign tenant destination', () => {
        const href = dashboardExecutionHref(context());

        expect(href).not.toBe('/module/execution');
        expect(href).not.toContain('/tenants/beta/');
        expect(href).toContain('/tenants/alpha%20workspace/');
    });

    it('fails closed unless the active membership has both execution permissions', () => {
        expect(canOpenDashboardExecution(context(['tenant.view']))).toBe(false);
        expect(canOpenDashboardExecution(context(['tenant.view', 'execution.view']))).toBe(false);
        expect(canOpenDashboardExecution(context(['tenant.view', 'operations.manage']))).toBe(false);
        expect(canOpenDashboardExecution(context())).toBe(true);
        expect(canOpenDashboardExecution(context(['*']))).toBe(true);
    });
});
