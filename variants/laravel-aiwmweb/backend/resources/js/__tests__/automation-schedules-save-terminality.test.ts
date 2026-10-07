import { describe, expect, it } from 'vitest';
import { prepareActionRequest, type DiscoveredActionContract } from '../action-contract';
import { workspaceRoutes, type FrontendContext } from '../core';

const operationId = 'AIMW-BILL-B6BE029CB8';

function context(): FrontendContext & { tenant: FrontendContext['tenant'] & { id: number } } {
    return {
        user: { id: 7, name: 'Operator', email: 'operator@example.test' },
        tenant: { id: 10, slug: 'alpha', name: 'Alpha' },
        tenants: [],
        permissions: ['operations.manage'],
        connectors: [],
        capabilities: {},
        api: { schedules: '/tenants/alpha/admin/schedules' },
        actions: {},
    };
}

const contract: DiscoveredActionContract = {
    operation_id: operationId,
    canonical_kind: 'visible_control',
    tenant_id: 10,
    tenant_slug: 'alpha',
    permission: 'operations.manage',
    endpoint: '/api/tenants/alpha/automation-schedules/save',
    method: 'POST',
    availability: { state: 'enabled' },
    reconcile_api_key: 'schedules',
    idempotency_required: true,
    fields: [
        { key: 'job', type: 'number', label: { en: 'Existing schedule ID', ar: 'معرّف الجدول الحالي' } },
        { key: 'expected_version', type: 'number', label: { en: 'Expected version', ar: 'الإصدار المتوقع' } },
        { key: 'name', type: 'text', label: { en: 'Job name', ar: 'اسم المهمة' }, required: true },
        { key: 'site_id', type: 'number', label: { en: 'Site ID', ar: 'معرّف الموقع' }, required: true },
        { key: 'type', type: 'select', label: { en: 'Job type', ar: 'نوع المهمة' }, required: true },
        { key: 'frequency', type: 'select', label: { en: 'Frequency', ar: 'التكرار' }, required: true },
        { key: 'interval_value', type: 'number', label: { en: 'Interval', ar: 'الفاصل' }, required: true },
        { key: 'time_of_day', type: 'text', label: { en: 'Run time UTC', ar: 'وقت التشغيل UTC' }, required: true },
        { key: 'enabled', type: 'select', label: { en: 'Enabled', ar: 'مفعلة' }, required: true },
        { key: 'retry_count', type: 'number', label: { en: 'Retry count', ar: 'عدد إعادة المحاولة' }, required: true },
    ],
};

const createValues = {
    name: 'Nightly synchronization',
    site_id: 12,
    type: 'Synchronization',
    frequency: 'daily',
    interval_value: 1,
    time_of_day: '02:30',
    enabled: 1,
    retry_count: 2,
};

describe('Automation Schedules SaveAsync terminality', () => {
    it('binds the canonical schedules workspace SaveAsync control', () => {
        const route = workspaceRoutes.find((candidate) => candidate.key === 'schedules');
        expect(route?.path).toBe('/module/schedules');
        expect(route?.controls).toContain('schedules.save');
        expect(operationId).toBe('AIMW-BILL-B6BE029CB8');
    });

    it('prepares a create request with a browser idempotency key and source schedule fields', () => {
        const prepared = prepareActionRequest(contract, context(), createValues);

        expect(prepared.endpoint).toBe('/api/tenants/alpha/automation-schedules/save');
        expect(prepared.method).toBe('POST');
        expect(prepared.operationId).toBe(operationId);
        expect(prepared.headers?.['Idempotency-Key']).toMatch(/^[0-9a-f-]{36}$/i);
        expect(JSON.parse(prepared.body ?? '{}')).toEqual(createValues);
    });

    it('prepares edit mode through the same source SaveAsync action with job and expected version', () => {
        const prepared = prepareActionRequest(contract, context(), {
            ...createValues,
            job: 41,
            expected_version: 3,
            name: 'Edited schedule',
        });

        expect(JSON.parse(prepared.body ?? '{}')).toEqual({
            ...createValues,
            job: 41,
            expected_version: 3,
            name: 'Edited schedule',
        });
    });

    it('fails closed for a foreign tenant or missing permission contract', () => {
        expect(() => prepareActionRequest(
            { ...contract, tenant_id: 99, tenant_slug: 'beta' },
            context(),
            createValues,
        )).toThrow(/active tenant/);

        expect(() => prepareActionRequest(
            contract,
            { ...context(), permissions: [] },
            createValues,
        )).toThrow(/operations\.manage/);
    });
});
