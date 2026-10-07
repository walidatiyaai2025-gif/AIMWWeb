import { describe, expect, it } from 'vitest';
import { prepareActionRequest, type DiscoveredActionContract } from '../action-contract';
import { workspaceRoutes, type FrontendContext } from '../core';

const operationId = 'AIMW-BILL-B6BE029CB8';

const context = (): FrontendContext & { tenant: FrontendContext['tenant'] & { id: number } } => ({
    user: { id: 7, name: 'Operator', email: 'operator@example.test' },
    tenant: { id: 10, slug: 'alpha', name: 'Alpha' },
    tenants: [],
    permissions: ['operations.manage', 'automation.view'],
    connectors: [],
    capabilities: {},
    api: { schedules: '/tenants/alpha/admin/schedules' },
    actions: {},
});

const contract: DiscoveredActionContract = {
    operation_id: operationId,
    canonical_kind: 'visible_control',
    tenant_id: 10,
    tenant_slug: 'alpha',
    permission: 'operations.manage',
    endpoint: '/api/tenants/alpha/automation-schedules/jobs/save',
    method: 'POST',
    availability: { state: 'enabled' },
    reconcile_api_key: 'schedules',
    idempotency_required: true,
    fields: [],
};

describe('Automation Schedules SaveAsync terminality', () => {
    it('surfaces the canonical save control on schedules', () => {
        const route = workspaceRoutes.find((candidate) => candidate.key === 'schedules');
        expect(route?.path).toBe('/module/schedules');
        expect(route?.controls).toContain('schedules.save');
        expect(operationId).toBe('AIMW-BILL-B6BE029CB8');
    });

    it('prepares a tenant-bound idempotent save request', () => {
        const request = prepareActionRequest(contract, context(), {
            name: 'Nightly synchronization',
            site_id: 41,
            type: 'Synchronization',
            frequency: 'daily',
            interval_value: 1,
            time_of_day: '02:30',
            enabled: 1,
            retry_count: 2,
        });

        expect(request.endpoint).toBe('/api/tenants/alpha/automation-schedules/jobs/save');
        expect(request.method).toBe('POST');
        expect(request.operationId).toBe(operationId);
        expect(request.headers?.['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/i);
    });

    it('rejects a discovered save contract from another tenant', () => {
        expect(() => prepareActionRequest(
            { ...contract, tenant_id: 99, tenant_slug: 'beta' },
            context(),
            { name: 'Denied' },
        )).toThrow(/active tenant/);
    });
});
