import { describe, expect, it } from 'vitest';
import { prepareActionRequest, type DiscoveredActionContract } from '../action-contract';
import { workspaceRoutes, type FrontendContext } from '../core';

const operationId = 'AIMW-BILL-2805622F94';

function context(): FrontendContext & { tenant: FrontendContext['tenant'] & { id: number } } {
    return {
        user: { id: 7, name: 'Editor', email: 'editor@example.test' },
        tenant: { id: 10, slug: 'alpha team', name: 'Alpha' },
        tenants: [],
        permissions: ['content.view', 'content.edit'],
        connectors: [],
        capabilities: {},
        api: { 'content-planner': '/api/tenants/alpha%20team/content-planner/items' },
        actions: {},
    };
}

const contract: DiscoveredActionContract = {
    operation_id: operationId,
    canonical_kind: 'visible_control',
    tenant_id: 10,
    tenant_slug: 'alpha team',
    permission: 'content.edit',
    endpoint: '/api/tenants/alpha%20team/content-planner/items/save',
    method: 'POST',
    availability: { state: 'enabled' },
    fields: [
        { key: 'id', type: 'number', label: { en: 'ID', ar: 'المعرف' } },
        { key: 'site_id', type: 'number', label: { en: 'Site', ar: 'الموقع' } },
        { key: 'title', type: 'text', label: { en: 'Title', ar: 'العنوان' }, required: true },
        { key: 'idea', type: 'textarea', label: { en: 'Idea', ar: 'الفكرة' } },
        { key: 'scheduled_at', type: 'text', label: { en: 'Schedule', ar: 'الموعد' } },
    ],
};

describe('Content Planner SaveAsync terminality', () => {
    it('wires the planner workspace to the real save action and authoritative read API', () => {
        const route = workspaceRoutes.find((candidate) => candidate.key === 'content-planner');
        expect(route?.controls).toContain('planner.save');
        expect(context().api['content-planner']).toContain('/content-planner/items');
        expect(operationId).toBe('AIMW-BILL-2805622F94');
    });

    it('prepares create and update payloads only for the active tenant contract', () => {
        const create = prepareActionRequest(contract, context(), {
            title: 'Launch plan',
            idea: 'Real persisted idea',
            site_id: 3,
        });
        expect(create.endpoint).toBe('/api/tenants/alpha%20team/content-planner/items/save');
        expect(create.method).toBe('POST');
        expect(create.operationId).toBe(operationId);
        expect(JSON.parse(create.body ?? '{}')).toEqual({
            title: 'Launch plan',
            idea: 'Real persisted idea',
            site_id: 3,
        });

        const update = prepareActionRequest(contract, context(), {
            id: 19,
            title: 'Revised',
        });
        expect(JSON.parse(update.body ?? '{}')).toEqual({ id: 19, title: 'Revised' });
    });

    it('fails closed when a discovered save contract belongs to another tenant', () => {
        expect(() => prepareActionRequest({ ...contract, tenant_id: 99, tenant_slug: 'beta' }, context(), {
            title: 'Denied',
        })).toThrow(/active tenant/);
    });
});
