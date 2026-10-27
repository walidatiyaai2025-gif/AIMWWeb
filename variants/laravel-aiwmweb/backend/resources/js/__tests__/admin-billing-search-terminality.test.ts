import { describe, expect, it } from 'vitest';
import { prepareActionRequest, type DiscoveredActionContract } from '../action-contract';
import { workspaceRoutes, type FrontendContext } from '../core';

const operationId = 'AIMW-BILL-9414B3FFEF';

function context(): FrontendContext & { tenant: FrontendContext['tenant'] & { id: number } } {
    return {
        user: { id: 7, name: 'Platform Admin', email: 'admin@example.test' },
        tenant: { id: 10, slug: 'alpha', name: 'Alpha' },
        tenants: [],
        permissions: ['*'],
        connectors: [],
        capabilities: {},
        api: { 'admin-billing-support': '/api/tenants/alpha/billing/admin/subscriptions' },
        actions: {},
    };
}

const contract: DiscoveredActionContract = {
    operation_id: operationId,
    canonical_kind: 'visible_control',
    tenant_id: 10,
    tenant_slug: 'alpha',
    permission: null,
    endpoint: '/api/tenants/alpha/billing/admin/subscriptions',
    method: 'GET',
    availability: { state: 'enabled' },
    reconcile_api_key: 'admin-billing-support',
    idempotency_required: false,
    fields: [
        {
            key: 'q',
            type: 'text',
            label: { en: 'Username, account ID, or PayPal reference', ar: 'اسم المستخدم أو معرّف الحساب أو مرجع PayPal' },
            required: false,
            query: true,
        },
    ],
};

describe('Admin Billing SearchAsync terminality', () => {
    it('binds the canonical support workspace and visible control', () => {
        const route = workspaceRoutes.find((candidate) => candidate.key === 'admin-billing-support');
        expect(route?.path).toBe('/admin/billing-support');
        expect(route?.controls).toContain('billing.support.search');
        expect(operationId).toBe('AIMW-BILL-9414B3FFEF');
    });

    it('encodes the support query in the GET URL without a body or idempotency key', () => {
        const prepared = prepareActionRequest(contract, context(), {
            q: 'alpha.support@example.test',
        });

        expect(prepared.endpoint).toBe('/api/tenants/alpha/billing/admin/subscriptions?q=alpha.support%40example.test');
        expect(prepared.method).toBe('GET');
        expect(prepared.body).toBeUndefined();
        expect(prepared.headers).toBeUndefined();
        expect(prepared.operationId).toBe(operationId);
    });

    it('keeps an empty search bounded to the tenant endpoint and fails closed for foreign tenant contracts', () => {
        const prepared = prepareActionRequest(contract, context(), { q: '' });
        expect(prepared.endpoint).toBe('/api/tenants/alpha/billing/admin/subscriptions');

        expect(() => prepareActionRequest(
            { ...contract, tenant_id: 99, tenant_slug: 'beta' },
            context(),
            { q: 'foreign' },
        )).toThrow(/active tenant/);
    });
});
