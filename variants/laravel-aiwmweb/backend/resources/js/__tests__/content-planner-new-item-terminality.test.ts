import { describe, expect, it } from 'vitest';
import { CONTENT_PLANNER_NEW_ITEM_OPERATION_ID } from '../pages';
import { workspaceRoutes, type FrontendContext } from '../core';
import { prepareActionRequest, type DiscoveredActionContract } from '../action-contract';

const context: FrontendContext & { tenant: FrontendContext['tenant'] & { id: number } } = {
    user: { id: 7, name: 'Editor', email: 'editor@example.test' },
    tenant: { id: 10, slug: 'alpha', name: 'Alpha' },
    tenants: [],
    permissions: ['content.view', 'content.edit'],
    connectors: [],
    capabilities: {},
    api: { 'content-planner': '/api/tenants/alpha/content-planner/items' },
    actions: {},
};

const saveContract: DiscoveredActionContract = {
    operation_id: 'AIMW-BILL-2805622F94',
    canonical_kind: 'visible_control',
    tenant_id: 10,
    tenant_slug: 'alpha',
    permission: 'content.edit',
    endpoint: '/api/tenants/alpha/content-planner/items/save',
    method: 'POST',
    availability: { state: 'enabled' },
    fields: [
        { key: 'id', type: 'number', label: { en: 'ID', ar: 'المعرف' } },
        { key: 'title', type: 'text', label: { en: 'Title', ar: 'العنوان' }, required: true },
    ],
};

describe('Content Planner NewItem terminality', () => {
    it('binds exactly the NewItem operation to the existing planner create/save control', () => {
        expect(CONTENT_PLANNER_NEW_ITEM_OPERATION_ID).toBe('AIMW-BILL-3ABDE4E48F');
        const route = workspaceRoutes.find((candidate) => candidate.key === 'content-planner');
        expect(route?.controls).toContain('planner.save');
    });

    it('keeps NewItem non-mutating until the user explicitly submits the real save contract', () => {
        const prepared = prepareActionRequest(saveContract, context, { title: 'New idea' });
        expect(prepared.operationId).toBe('AIMW-BILL-2805622F94');
        expect(prepared.endpoint).toBe('/api/tenants/alpha/content-planner/items/save');
        expect(JSON.parse(prepared.body ?? '{}')).toEqual({ title: 'New idea' });
    });
});
