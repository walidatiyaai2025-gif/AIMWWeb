import { describe, expect, it, vi } from 'vitest';
import { normalizeObservabilityCollection } from '../observability-collection-contract';
import { readWorkspaceCollectionResponse } from '../wordpress-collection-contract';

describe('tenant observability read contracts (#1514)', () => {
    it('projects both authoritative diagnostics arrays, rather than masking audit rows as empty', async () => {
        const response = {
            failed_operations: [{
                id: 17, type: 'sync.run', status: 'failed',
                created_at: '2026-11-07T10:15:00Z',
                payload: { secret: 'NEVER_SHOW_PAYLOAD' },
                result: { token: 'NEVER_SHOW_RESULT' },
            }],
            recent_audit: [{
                id: 19, event: 'site.updated', occurred_at: '2026-11-07T10:25:00Z',
                actor_user_id: 123, metadata: { password: 'NEVER_SHOW_METADATA' },
            }],
        };
        const result = await readWorkspaceCollectionResponse(
            'diagnostics', '/tenants/alpha/admin/diagnostics', vi.fn().mockResolvedValue(response),
        );
        expect(result).toEqual({
            data: [
                { source: 'failed_operation', record_id: 17, event_type: 'sync.run', status: 'failed', occurred_at: '2026-11-07T10:15:00Z' },
                { source: 'audit_event', record_id: 19, event_type: 'site.updated', status: 'recorded', occurred_at: '2026-11-07T10:25:00Z' },
            ],
            total: 2,
            current_page: 1,
            last_page: 1,
            meta: { failed_operations_count: 1, recent_audit_count: 1 },
        });
        expect(JSON.stringify(result)).not.toMatch(/NEVER_SHOW|actor_user_id|payload|result|metadata/i);
    });

    it('shows last updated failure time, with legacy fallback and safe malformed timestamp handling (#1566)', () => {
        const failure = (changes: Record<string, unknown>) => normalizeObservabilityCollection('diagnostics', {
            failed_operations: [{
                id: 8, type: 'sync.run', status: 'failed',
                created_at: '2026-11-01T08:00:00Z',
                ...changes,
            }],
            recent_audit: [],
        }) as { data: Array<{ occurred_at: string | null }> };

        expect(failure({ updated_at: '2026-11-08T00:12:00Z' }).data[0].occurred_at)
            .toBe('2026-11-08T00:12:00Z');
        expect(failure({}).data[0].occurred_at)
            .toBe('2026-11-01T08:00:00Z');
        expect(failure({ updated_at: '<script>SECRET</script>' }).data[0].occurred_at)
            .toBe('2026-11-01T08:00:00Z');
        expect(failure({ updated_at: '<script>SECRET</script>', created_at: null }).data[0].occurred_at)
            .toBeNull();
        expect(JSON.stringify(failure({ updated_at: '<script>SECRET</script>' })))
            .not.toContain('SECRET');
    });

    it('accepts genuinely empty diagnostics, without inventing sample events', () => {
        expect(normalizeObservabilityCollection('diagnostics', {
            failed_operations: [], recent_audit: [],
        })).toEqual({
            data: [], total: 0, current_page: 1, last_page: 1,
            meta: { failed_operations_count: 0, recent_audit_count: 0 },
        });
    });

    it('validates bounded diagnostic source evidence and drops unexpected fields', () => {
        const raw = {
            failed_operations: [{ id: 1, type: 'sync.run', created_at: '2026-11-07T10:15:00Z' }],
            recent_audit: [],
            meta: {
                scope: 'tenant_operations_and_audit',
                failed_operations_total: 105,
                audit_events_total: 0,
                failed_operations_truncated: true,
                recent_audit_truncated: false,
                limit_per_source: 100,
                empty_reason: 'not_empty',
                system_health_included: false,
                secret: 'LEAK_ME',
            },
        };
        // A 105-total response must actually contain the bounded 100 rows.
        const full = {
            ...raw,
            failed_operations: Array.from({ length: 100 }, (_, index) => ({
                id: index + 1, type: 'sync.run', created_at: '2026-11-07T10:15:00Z',
            })),
        };
        const response = normalizeObservabilityCollection('diagnostics', full) as {
            meta: Record<string, unknown>; data: unknown[];
        };
        expect(response.meta).toMatchObject({
            failed_operations_total: 105,
            failed_operations_truncated: true,
            audit_events_total: 0,
            system_health_included: false,
        });
        expect(response.data).toHaveLength(100);
        expect(JSON.stringify(response)).not.toContain('LEAK_ME');

        for (const invalid of [
            raw,
            { ...full, meta: { ...full.meta, failed_operations_total: 4 } },
            { ...full, meta: { ...full.meta, system_health_included: true } },
            { ...full, meta: { ...full.meta, empty_reason: 'no_tenant_events' } },
            { ...full, meta: { ...full.meta, secret: 'ok', audit_events_total: -1 } },
        ]) {
            expect(() => normalizeObservabilityCollection('diagnostics', invalid))
                .toThrow('Operational observability response was invalid');
        }
    });

    it('treats malformed HTTP 200 diagnostics as errors, never zero', async () => {
        const malformed = [
            null, [], {}, { data: [] }, { failed_operations: [] },
            { failed_operations: {}, recent_audit: [] },
            { failed_operations: [], recent_audit: {} },
            { failed_operations: [null], recent_audit: [] },
            { failed_operations: [], recent_audit: ['wrong'] },
            { failed_operations: [], recent_audit: [], error: 'failed' },
            { failed_operations: [], recent_audit: [], success: false },
            { failed_operations: Array.from({ length: 101 }, () => ({})), recent_audit: [] },
        ];
        for (const payload of malformed) {
            await expect(readWorkspaceCollectionResponse(
                'diagnostics', '/tenant/alpha/diagnostics', vi.fn().mockResolvedValue(payload),
            )).rejects.toThrow('Operational observability response was invalid');
        }
    });

    it('accepts logs only from the real data array and retains search results', async () => {
        const valid = { data: [{ id: 3, message: 'tenant-safe entry', level: 'warning' }] };
        const transport = vi.fn().mockResolvedValue(valid);
        expect(await readWorkspaceCollectionResponse('logs', '/tenants/alpha/admin/logs?search=q', transport)).toBe(valid);
        expect(transport).toHaveBeenCalledWith('/tenants/alpha/admin/logs?search=q');
        expect(normalizeObservabilityCollection('logs', { data: [] })).toEqual({ data: [] });
        for (const payload of [null, {}, { items: [] }, { data: {} }, { data: [null] }, { data: [], success: false }]) {
            expect(() => normalizeObservabilityCollection('logs', payload)).toThrow('Operational observability response was invalid');
        }
    });

    it('validates tenant-only log counts and strips unexpected evidence metadata', () => {
        const safe = {
            data: [],
            meta: {
                scope: 'tenant_operation_logs',
                tenant_records: 2,
                matching_records: 0,
                returned_records: 0,
                limit: 500,
                truncated: false,
                empty_reason: 'filter_matched_none',
            },
        };
        const dirty = { ...safe, meta: { ...safe.meta, api_key: 'DO_NOT_EXPORT' } };
        expect(normalizeObservabilityCollection('logs', dirty)).toEqual(safe);
        expect(JSON.stringify(normalizeObservabilityCollection('logs', dirty))).not.toContain('DO_NOT_EXPORT');

        const cases = [
            { ...safe, meta: { ...safe.meta, scope: 'laravel_raw_logs' } },
            { ...safe, meta: { ...safe.meta, returned_records: 8 } },
            { ...safe, meta: { ...safe.meta, tenant_records: -1 } },
            { ...safe, meta: { ...safe.meta, matching_records: 'many' } },
            { ...safe, meta: { ...safe.meta, empty_reason: '<script>' } },
            { ...safe, meta: null },
        ];
        for (const invalid of cases) {
            expect(() => normalizeObservabilityCollection('logs', invalid)).toThrow('Operational observability response was invalid');
        }

        expect(normalizeObservabilityCollection('logs', {
            data: [{ id: 7, level: 'info', message: 'operation entry' }],
            meta: {
                ...safe.meta, tenant_records: 1, matching_records: 1,
                returned_records: 1, empty_reason: 'not_empty',
            },
        })).toMatchObject({
            data: [{ id: 7 }], meta: { scope: 'tenant_operation_logs', matching_records: 1 },
        });
    });

    it('accepts only truthful server-paginated tenant logs metadata (#1608)', () => {
        const safe = {
            data: [{ id: 102, message: 'Visible event', level: 'info' }],
            current_page: 2, last_page: 3, per_page: 25, total: 60,
            meta: {
                scope: 'tenant_operation_logs', pagination_mode: 'server',
                tenant_records: 63, matching_records: 60,
                returned_records: 1, limit: 25, truncated: false,
                empty_reason: 'not_empty',
            },
        };
        expect(normalizeObservabilityCollection('logs', {
            ...safe, meta: { ...safe.meta, private_key: 'DO_NOT_EXPORT' },
        })).toEqual(safe);
        const invalidCases = [
            { ...safe, total: 61 },
            { ...safe, last_page: 1 },
            { ...safe, current_page: 4 },
            { ...safe, per_page: 500 },
            { ...safe, meta: { ...safe.meta, matching_records: 64 } },
            { ...safe, meta: { ...safe.meta, returned_records: 0 } },
            { ...safe, meta: { ...safe.meta, truncated: true } },
            { ...safe, meta: { ...safe.meta, pagination_mode: 'unknown' } },
        ];
        for (const payload of invalidCases) {
            expect(() => normalizeObservabilityCollection('logs', payload))
                .toThrow('Operational observability response was invalid');
        }
    });

    it('sanitizes unexpected diagnostic identifiers, free text and timestamps', () => {
        const rows = normalizeObservabilityCollection('diagnostics', {
            failed_operations: [{
                id: -2, type: 'private key: abc', failed_at: 'invalid timestamp',
                payload: { secret: 'never' },
            }],
            recent_audit: [{ id: 2.5, event: '<script>alert(1)</script>', occurred_at: 23 }],
        }) as { data: Array<Record<string, unknown>> };
        expect(rows.data[0]).toMatchObject({ record_id: null, event_type: 'unclassified', occurred_at: null });
        expect(rows.data[1]).toMatchObject({ record_id: null, event_type: 'unclassified', occurred_at: null });
        expect(JSON.stringify(rows)).not.toContain('<script>');
    });

    it('accepts complete real dates and rejects misleading diagnostic date prefixes (#1568)', () => {
        const project = (time: unknown, created: unknown = null) => normalizeObservabilityCollection('diagnostics', {
            failed_operations: [{ id: 9, type: 'sync.run', updated_at: time, created_at: created }],
            recent_audit: [{ id: 10, event: 'sync.failed', occurred_at: time }],
        }) as { data: Array<{ occurred_at: string | null }> };

        const good = [
            '2026-11-08T00:12:42Z', '2026-11-08T00:12:42.123456+03:00',
            '2026-11-08 00:12:42', '2024-02-29T23:59:59-05:00',
        ];
        for (const value of good) {
            expect(project(value).data.map(row => row.occurred_at)).toEqual([value, value]);
        }
        const bad = [
            '2026-11-08T00:12BAD', '2026-11-08T00:12:42SECRET',
            '2026-02-29T12:30:00Z', '2026-13-08T12:30:00Z',
            '2026-11-31T12:30:00Z', '2026-11-08T25:00:00Z',
            '2026-11-08T00:60:00Z', '2026-11-08T00:12:61Z',
            '2026-11-08T00:12:42+15:00', '2026-11-08T00:12:42+14:01',
            '0000-11-08T00:12:42Z', '2026-11-08T00:12:42Z<script>',
        ];
        for (const value of bad) {
            expect(project(value).data.map(row => row.occurred_at)).toEqual([null, null]);
            const fallback = project(value, '2026-11-07 10:15:00');
            expect(fallback.data[0].occurred_at).toBe('2026-11-07 10:15:00');
            expect(fallback.data[1].occurred_at).toBeNull();
            expect(JSON.stringify(fallback)).not.toContain(value);
        }
    });

    it('does not change unrelated resource contracts or swallow transport failures', async () => {
        const other = { ok: true, status: 'ready' };
        expect(normalizeObservabilityCollection('system-health', other)).toBe(other);
        await expect(readWorkspaceCollectionResponse(
            'diagnostics', '/tenants/alpha/admin/diagnostics',
            vi.fn().mockRejectedValue(new Error('HTTP 503')),
        )).rejects.toThrow('HTTP 503');
    });
});
