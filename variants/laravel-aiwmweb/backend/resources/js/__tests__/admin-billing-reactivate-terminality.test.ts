import { describe, expect, it } from 'vitest';
import { prepareActionRequest, type DiscoveredActionContract } from '../action-contract';
import { workspaceRoutes, type FrontendContext } from '../core';

const operationId = 'AIMW-BILL-29A4267B87';

function context(): FrontendContext & { tenant: FrontendContext['tenant'] & { id: number } } {
    return {
        user: { id: 7, name: 'Platform Admin', email: 'admin@example.test' },
        tenant: { id: 10, slug: 'alpha', name: 'Alpha' },
        tenants: [],
        permissions: ['*'],
        connectors: [],
        capabilities: {},
        api: { 'account.billing': '/tenants/alpha/route-api/billing-overview' },
        actions: {},
    };
}

const contract: DiscoveredActionContract = {
    operation_id: operationId,
    canonical_kind: 'visible_control',
    tenant_id: 10,
    tenant_slug: 'alpha',
    permission: null,
    endpoint: '/api/tenants/alpha/billing/admin/subscriptions/{subscription}/reactivate',
    method: 'POST',
    availability: { state: 'enabled' },
    reconcile_api_key: 'account.billing',
    fields: [
        { key: 'subscription', type: 'number', label: { en: 'Subscription ID', ar: 'معرّف الاشتراك' }, required: true, path: true },
        { key: 'reason', type: 'textarea', label: { en: 'Support reason', ar: 'سبب تدخل الدعم' }, required: true },
    ],
};

describe('Admin Billing ReactivateAsync terminality', () => {
    it('binds the canonical support route and visible control', () => {
        const route = workspaceRoutes.find((candidate) => candidate.key === 'admin-billing-support');
        expect(route?.path).toBe('/admin/billing-support');
        expect(route?.controls).toContain('billing.support.reactivate');
        expect(operationId).toBe('AIMW-BILL-29A4267B87');
    });

    it('binds subscription as a path-owned target and keeps only the support reason in the request body', () => {
        const prepared = prepareActionRequest(contract, context(), {
            subscription: 41,
            reason: 'Case SUP-1001 verified local lockout',
        });

        expect(prepared.endpoint).toBe('/api/tenants/alpha/billing/admin/subscriptions/41/reactivate');
        expect(prepared.method).toBe('POST');
        expect(prepared.operationId).toBe(operationId);
        expect(JSON.parse(prepared.body ?? '{}')).toEqual({
            reason: 'Case SUP-1001 verified local lockout',
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
