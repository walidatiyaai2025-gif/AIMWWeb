import { describe, expect, it } from 'vitest';
import {
    canOpenContentPlannerExecution,
    contentPlannerExecutionHref,
    CONTENT_PLANNER_EXECUTION_LINK_OPERATION_ID,
    CONTENT_PLANNER_EXECUTION_LINK_SOURCE_CONTROL_TARGET,
    CONTENT_PLANNER_EXECUTION_LINK_SOURCE_OPERATION_KEY,
    CONTENT_PLANNER_EXECUTION_LINK_SOURCE_PATH,
} from '../content-planner-execution-link-control';
import { workspaceRoutes, type FrontendContext } from '../core';

const context = (permissions: string[] = ['tenant.view', 'content.view', 'execution.view', 'operations.manage']): FrontendContext => ({
    user: { id: 1, name: 'Operator', email: 'operator@example.test' },
    tenant: { slug: 'alpha workspace', name: 'Alpha' },
    tenants: [{ slug: 'alpha workspace', name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

describe('canonical Content Planner execution visible control', () => {
    it('binds the exact canonical source operation to the guarded execution workspace', () => {
        const plannerRoute = workspaceRoutes.find((route) => route.key === 'content-planner');
        const executionRoute = workspaceRoutes.find((route) => route.key === 'execution');

        expect(CONTENT_PLANNER_EXECUTION_LINK_OPERATION_ID).toBe('AIMW-BILL-0E16C6E79E');
        expect(CONTENT_PLANNER_EXECUTION_LINK_SOURCE_OPERATION_KEY).toBe(
            'visible:src/AIWordPressManager.Web/Components/Pages/ContentPlanner.razor:/content-planner:/module/execution:/module/execution',
        );
        expect(CONTENT_PLANNER_EXECUTION_LINK_SOURCE_PATH).toBe('/content-planner');
        expect(CONTENT_PLANNER_EXECUTION_LINK_SOURCE_CONTROL_TARGET).toBe('/module/execution');
        expect(plannerRoute?.path).toBe('/content-planner');
        expect(plannerRoute?.permission).toBe('content.view');
        expect(executionRoute?.path).toBe('/module/execution');
        expect(executionRoute?.permission).toBe('execution.view');
        expect(contentPlannerExecutionHref(context())).toBe('/tenants/alpha%20workspace/module/execution');
    });

    it('never emits the unqualified source target or a foreign tenant destination', () => {
        const href = contentPlannerExecutionHref(context());

        expect(href).not.toBe('/module/execution');
        expect(href).not.toContain('/tenants/beta/');
        expect(href).toContain('/tenants/alpha%20workspace/');
    });

    it('fails closed unless Content Planner and Execution permissions are all present', () => {
        expect(canOpenContentPlannerExecution(context(['tenant.view', 'execution.view', 'operations.manage']))).toBe(false);
        expect(canOpenContentPlannerExecution(context(['tenant.view', 'content.view', 'operations.manage']))).toBe(false);
        expect(canOpenContentPlannerExecution(context(['tenant.view', 'content.view', 'execution.view']))).toBe(false);
        expect(canOpenContentPlannerExecution(context())).toBe(true);
        expect(canOpenContentPlannerExecution(context(['*']))).toBe(true);
    });
});
