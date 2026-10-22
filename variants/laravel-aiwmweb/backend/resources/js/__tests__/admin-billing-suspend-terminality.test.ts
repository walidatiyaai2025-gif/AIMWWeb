import { describe, expect, it } from 'vitest';
import { prepareActionRequest, type DiscoveredActionContract } from '../action-contract';
import { workspaceRoutes, type FrontendContext } from '../core';

const operationId = 'AIMW-BILL-602CCA4A55';

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
    endpoint: '/api/tenants/alpha/billing/admin/subscriptions/{subscription}/suspend',
    method: 'POST',
    availability: { state: 'enabled' },
    reconcile_api_key: 'admin-billing-support',
    idempotency_required: true,
    fields: [
        { key: 'subscription', type: 'number', label: { en: 'Subscription ID', ar: 'معرّف الاشتراك' }, required: true, path: true },
        { key: 'reason', type: 'textarea', label: { en: 'Support reason', ar: 'سبب تدخل الدعم' }, required: true },
    ],
};

describe('Admin Billing SuspendAsync terminality', () => {
    it('binds the canonical support route and visible control', () => {
        const route = workspaceRoutes.find((candidate) => candidate.key === 'admin-billing-support');
        expect(route?.path).toBe('/admin/billing-support');
        expect(route?.controls).toContain('billing.support.suspend');
        expect(operationId).toBe('AIMW-BILL-602CCA4A55');
    });

    it('binds the subscription as a path target and sends only the support reason with idempotency', () => {
        const prepared = prepareActionRequest(contract, context(), {
            subscription: 41,
            reason: 'Case SUP-3001 verified access suspension',
        });

        expect(prepared.endpoint).toBe('/api/tenants/alpha/billing/admin/subscriptions/41/suspend');
        expect(prepared.method).toBe('POST');
        expect(prepared.operationId).toBe(operationId);
        expect(prepared.headers?.['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/i);
        expect(JSON.parse(prepared.body ?? '{}')).toEqual({
            reason: 'Case SUP-3001 verified access suspension',
        });
    });

    it('fails closed when the discovered support action belongs to another tenant', () => {
        expect(() => prepareActionRequest(
            { ...contract, tenant_id: 99, tenant_slug: 'beta' },
            context(),
            { subscription: 41, reason: 'Denied foreign tenant contract' },
        )).toThrow(/active tenant/);
    });
});
