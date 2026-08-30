import { describe, expect, it } from 'vitest';
import { prepareActionRequest, type DiscoveredActionContract } from '../action-contract';
import type { FrontendContext } from '../core';

const context = {
    user: { id: 1, name: 'Operator', email: 'operator@example.test' },
    tenant: { id: 10, slug: 'alpha', name: 'Alpha' },
    tenants: [],
    permissions: ['*'], connectors: [], capabilities: {}, api: {}, actions: {},
    active_site: { id: 44, name: 'Alpha site' },
} as unknown as FrontendContext;

const cases = [
    ['AIMW-BILL-5B1B140851', 'GET', '/tenants/alpha/admin/schedules'],
    ['AIMW-BILL-07A0F6427B', 'GET', '/tenants/alpha/admin/backups'],
    ['AIMW-BILL-090028F39C', 'GET', '/api/v1/tenants/alpha/email/deliveries'],
    ['AIMW-BILL-75CF9DBDA4', 'GET', '/tenants/alpha/admin/operations'],
    ['AIMW-BILL-37EE8ED7EE', 'POST', '/api/v1/tenants/alpha/sites/44/sync'],
    ['AIMW-BILL-B9162DF5EF', 'GET', '/tenants/alpha/admin/logs'],
    ['AIMW-BILL-B15FB13792', 'POST', '/api/v1/tenants/alpha/sites/44/taxonomy'],
    ['AIMW-SYNC-6FCFE15D24', 'PATCH', '/tenants/alpha/admin/members/7'],
    ['AIMW-SYNC-724345B409', 'GET', '/tenants/alpha/admin/members'],
    ['AIMW-SYNC-8D6F1C5EAA', 'GET', '/api/v1/tenants/alpha/sites/44/comments'],
    ['AIMW-SYNC-461B1075DE', 'GET', '/api/v1/tenants/alpha/sites/44/media'],
    ['AIMW-SYNC-7877CAF7E8', 'GET', '/tenants/alpha/admin/roles'],
    ['AIMW-SYNC-0FF542A678', 'GET', '/api/v1/tenants/alpha/sites/44/taxonomy'],
] as const;

describe('focused action-contract terminality', () => {
    it.each(cases)('binds %s to its real tenant-scoped request', (operationId, method, endpoint) => {
        const membership = operationId === 'AIMW-SYNC-6FCFE15D24';
        const contract = {
            operation_id: operationId, canonical_kind: 'visible_control', tenant_id: 10,
            tenant_slug: 'alpha', site_id: endpoint.includes('/sites/44/') ? 44 : null,
            permission: null, capability: 'focused.contract', endpoint: membership ? endpoint.replace('/7', '/{membership}') : endpoint,
            method, availability: { state: 'enabled', reason: null },
            fixed: operationId === 'AIMW-BILL-37EE8ED7EE' ? { full: false } : membership ? { status: 'inactive' } : {},
            fields: membership ? [{ key: 'membership', type: 'number', label: { en: 'Membership' }, required: true, path: true }] : [],
        } as DiscoveredActionContract;
        const request = prepareActionRequest(contract, context, membership ? { membership: 7 } : {});
        expect(request.operationId).toBe(operationId);
        expect(request.method).toBe(method);
        expect(request.endpoint).toBe(endpoint);
        expect(method === 'GET' ? request.body : request.body).toBe(method === 'GET' ? undefined : expect.any(String));
    });
});
