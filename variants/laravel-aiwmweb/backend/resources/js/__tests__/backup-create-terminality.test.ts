import { describe, expect, it } from 'vitest';
import { prepareActionRequest, type DiscoveredActionContract } from '../action-contract';
import { workspaceRoutes, type FrontendContext } from '../core';

const operationId = 'AIMW-BILL-DDA412F087';

function context(): FrontendContext & { tenant: FrontendContext['tenant'] & { id: number } } {
    return {
        user: { id: 7, name: 'Platform Admin', email: 'admin@example.test' },
        tenant: { id: 10, slug: 'alpha', name: 'Alpha' },
        tenants: [],
        permissions: ['*'],
        connectors: [],
        capabilities: {},
        api: { backups: '/tenants/alpha/admin/backups' },
        actions: {},
    };
}

const contract: DiscoveredActionContract = {
    operation_id: operationId,
    canonical_kind: 'visible_control',
    tenant_id: 10,
    tenant_slug: 'alpha',
    permission: null,
    endpoint: '/api/tenants/alpha/backups',
    method: 'POST',
    availability: { state: 'enabled' },
    reconcile_api_key: 'backups',
    fields: [],
};

describe('BackupRestore CreateAsync terminality', () => {
    it('surfaces the canonical create control on Backup & Restore', () => {
        const route = workspaceRoutes.find((candidate) => candidate.key === 'backups');
        expect(route?.path).toBe('/module/backups');
        expect(route?.controls).toContain('backups.create');
        expect(operationId).toBe('AIMW-BILL-DDA412F087');
    });

    it('binds note and recovery confirmation only to the active tenant endpoint', () => {
        const request = prepareActionRequest(contract, context(), {
            note: 'Before upgrade',
            recovery_secret: 'correct-horse-battery-staple-2026',
            recovery_secret_confirmation: 'correct-horse-battery-staple-2026',
        });

        expect(request.endpoint).toBe('/api/tenants/alpha/backups');
        expect(request.method).toBe('POST');
        expect(request.operationId).toBe(operationId);
        expect(JSON.parse(request.body ?? '{}')).toEqual({
            note: 'Before upgrade',
            recovery_secret: 'correct-horse-battery-staple-2026',
            recovery_secret_confirmation: 'correct-horse-battery-staple-2026',
        });
    });

    it('fails closed for a foreign tenant contract', () => {
        expect(() => prepareActionRequest(
            { ...contract, tenant_id: 99, tenant_slug: 'beta' },
            context(),
            { note: 'Denied' },
        )).toThrow(/active tenant/);
    });
});
