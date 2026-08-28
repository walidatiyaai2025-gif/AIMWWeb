import { describe, expect, it } from 'vitest';
import { ActionContractError, prepareActionRequest, type DiscoveredActionContract } from '../action-contract';
import type { FrontendContext } from '../core';

const context = (): FrontendContext => ({
    user: { id: 1, name: 'Operator', email: 'operator@example.test' },
    tenant: { id: 10, slug: 'alpha', name: 'Alpha' } as FrontendContext['tenant'],
    tenants: [],
    permissions: ['tenant.view', 'members.manage', 'seo.manage'],
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
    active_site: { id: 44, name: 'Alpha site' },
} as FrontendContext);

const contract = (overrides: Partial<DiscoveredActionContract> = {}): DiscoveredActionContract => ({
    operation_id: 'AIMW-SYNC-6FCFE15D24',
    canonical_kind: 'visible_control',
    tenant_id: 10,
    tenant_slug: 'alpha',
    site_id: null,
    permission: 'members.manage',
    capability: 'users.disable',
    endpoint: '/tenants/alpha/admin/members/{membership}',
    method: 'PATCH',
    availability: { state: 'enabled', reason: null },
    fixed: { status: 'inactive' },
    fields: [{ key: 'membership', type: 'number', label: { en: 'Membership ID', ar: 'معرّف العضوية' }, required: true, path: true }],
    ...overrides,
});

describe('canonical action contracts', () => {
    it('carries operation identity into the prepared request and binds path values', () => {
        expect(prepareActionRequest(contract(), context(), { membership: 7 })).toEqual({
            endpoint: '/tenants/alpha/admin/members/7',
            method: 'PATCH',
            body: JSON.stringify({ status: 'inactive' }),
            operationId: 'AIMW-SYNC-6FCFE15D24',
        });
    });

    it('supports canonical read controls without fabricating a request body', () => {
        expect(prepareActionRequest(contract({
            operation_id: 'AIMW-SYNC-A9E956A4DA',
            permission: 'tenant.view',
            capability: 'sites.refresh',
            endpoint: '/api/tenants/alpha/sites',
            method: 'GET',
            fixed: {},
            fields: [],
        }), context(), {})).toEqual({
            endpoint: '/api/tenants/alpha/sites',
            method: 'GET',
            body: undefined,
            operationId: 'AIMW-SYNC-A9E956A4DA',
        });
    });

    it('rejects a contract from the wrong tenant', () => {
        expect(() => prepareActionRequest(contract({ tenant_id: 99 }), context(), { membership: 7 }))
            .toThrowError(ActionContractError);
    });

    it('rejects a contract from the wrong active site', () => {
        expect(() => prepareActionRequest(contract({ site_id: 45 }), context(), { membership: 7 }))
            .toThrow('active site');
    });

    it('honors contract permission requirements', () => {
        const denied = context();
        denied.permissions = ['tenant.view'];
        expect(() => prepareActionRequest(contract(), denied, { membership: 7 }))
            .toThrow('members.manage');
    });

    it('keeps unavailable actions fail-closed', () => {
        expect(() => prepareActionRequest(contract({ availability: { state: 'pending_integration', reason: 'Site context required.' } }), context(), { membership: 7 }))
            .toThrow('Site context required.');
    });

    it('rejects malformed or missing canonical operation IDs', () => {
        expect(() => prepareActionRequest(contract({ operation_id: 'users.disable' }), context(), { membership: 7 }))
            .toThrow('canonical operation identity');
    });
});
