import { describe, expect, it } from 'vitest';
import {
    CONTENT_PLANNER_AI_CENTER_LINK_OPERATION_ID,
    CONTENT_PLANNER_AI_CENTER_LINK_SOURCE_CONTROL_TARGET,
    CONTENT_PLANNER_AI_CENTER_LINK_SOURCE_OPERATION_KEY,
    CONTENT_PLANNER_AI_CENTER_LINK_SOURCE_PATH,
    canOpenContentPlannerAiCenter,
    contentPlannerAiCenterHref,
} from '../content-planner-ai-center-link-control';
import type { FrontendContext } from '../core';

const context = (permissions: string[]): FrontendContext => ({
    user: { id: 1, name: 'Planner', email: 'planner@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

describe('content planner AI Center link', () => {
    it('binds the exact canonical visible control contract', () => {
        expect(CONTENT_PLANNER_AI_CENTER_LINK_OPERATION_ID).toBe('AIMW-BILL-CAFE798AA3');
        expect(CONTENT_PLANNER_AI_CENTER_LINK_SOURCE_OPERATION_KEY).toBe(
            'visible:src/AIWordPressManager.Web/Components/Pages/ContentPlanner.razor:/content-planner:/ai-center:/ai-center',
        );
        expect(CONTENT_PLANNER_AI_CENTER_LINK_SOURCE_PATH).toBe('/content-planner');
        expect(CONTENT_PLANNER_AI_CENTER_LINK_SOURCE_CONTROL_TARGET).toBe('/ai-center');
    });

    it('derives a tenant-qualified destination instead of a global link', () => {
        expect(contentPlannerAiCenterHref(context(['tenant.view', 'content.view', 'ai.use'])))
            .toBe('/tenants/alpha/ai-center');
    });

    it('fails closed unless both source and destination permissions are present', () => {
        expect(canOpenContentPlannerAiCenter(context(['tenant.view', 'content.view', 'ai.use']))).toBe(true);
        expect(canOpenContentPlannerAiCenter(context(['tenant.view', 'content.view']))).toBe(false);
        expect(canOpenContentPlannerAiCenter(context(['tenant.view', 'ai.use']))).toBe(false);
        expect(canOpenContentPlannerAiCenter(context(['content.view', 'ai.use']))).toBe(false);
    });
});
