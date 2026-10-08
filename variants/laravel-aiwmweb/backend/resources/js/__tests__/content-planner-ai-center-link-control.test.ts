import { describe, expect, it } from 'vitest';
import {
    canOpenContentPlannerAiCenter,
    contentPlannerAiCenterHref,
    CONTENT_PLANNER_AI_CENTER_LINK_OPERATION_ID,
    CONTENT_PLANNER_AI_CENTER_LINK_SOURCE_CONTROL_TARGET,
    CONTENT_PLANNER_AI_CENTER_LINK_SOURCE_OPERATION_KEY,
    CONTENT_PLANNER_AI_CENTER_LINK_SOURCE_PATH,
} from '../content-planner-ai-center-link-control';
import { workspaceRoutes, type FrontendContext } from '../core';

const context = (permissions: string[] = ['tenant.view', 'content.view', 'ai.use']): FrontendContext => ({
    user: { id: 1, name: 'Operator', email: 'operator@example.test' },
    tenant: { slug: 'alpha workspace', name: 'Alpha' },
    tenants: [{ slug: 'alpha workspace', name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

describe('canonical Content Planner AI Center visible control', () => {
    it('binds the exact canonical source operation to the tenant-qualified AI Center', () => {
        const plannerRoute = workspaceRoutes.find((route) => route.key === 'content-planner');
        const aiCenterRoute = workspaceRoutes.find((route) => route.key === 'ai-center');

        expect(CONTENT_PLANNER_AI_CENTER_LINK_OPERATION_ID).toBe('AIMW-BILL-CAFE798AA3');
        expect(CONTENT_PLANNER_AI_CENTER_LINK_SOURCE_OPERATION_KEY).toBe(
            'visible:src/AIWordPressManager.Web/Components/Pages/ContentPlanner.razor:/content-planner:/ai-center:/ai-center',
        );
        expect(CONTENT_PLANNER_AI_CENTER_LINK_SOURCE_PATH).toBe('/content-planner');
        expect(CONTENT_PLANNER_AI_CENTER_LINK_SOURCE_CONTROL_TARGET).toBe('/ai-center');
        expect(plannerRoute?.permission).toBe('content.view');
        expect(aiCenterRoute?.permission).toBe('ai.use');
        expect(contentPlannerAiCenterHref(context())).toBe('/tenants/alpha%20workspace/ai-center');
    });

    it('never emits the unqualified source target or a foreign tenant destination', () => {
        const href = contentPlannerAiCenterHref(context());

        expect(href).not.toBe('/ai-center');
        expect(href).not.toContain('/tenants/beta/');
        expect(href).toContain('/tenants/alpha%20workspace/');
    });

    it('fails closed unless both planner visibility and AI Center permission are present', () => {
        expect(canOpenContentPlannerAiCenter(context(['tenant.view', 'ai.use']))).toBe(false);
        expect(canOpenContentPlannerAiCenter(context(['tenant.view', 'content.view']))).toBe(false);
        expect(canOpenContentPlannerAiCenter(context())).toBe(true);
        expect(canOpenContentPlannerAiCenter(context(['*']))).toBe(true);
    });
});
