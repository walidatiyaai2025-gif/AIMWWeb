/**
 * Contract adapter for tenant-scoped operational observability reads.
 * HTTP 200 with the wrong shape is a contract failure, never "zero records".
 *
 * Diagnostics intentionally does not expose server audit metadata, execution
 * payload/result or other potentially sensitive nested values in the grid.
 */
const record = (value: unknown): value is Record<string, unknown> =>
    value !== null && typeof value === 'object' && !Array.isArray(value);

const invalid = () => new Error(
    'Operational observability response was invalid. Refresh or inspect the API contract; zero records cannot be inferred.',
);

const technical = (value: unknown): string =>
    typeof value === 'string' && /^[a-z0-9_.:-]{1,100}$/i.test(value)
        ? value
        : 'unclassified';

const timestamp = (value: unknown): string | null =>
    typeof value === 'string' && /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/.test(value) && value.length <= 40
        ? value
        : null;

const identifier = (value: unknown): number | null =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0 ? value : null;

export function normalizeObservabilityCollection(routeKey: string, payload: unknown): unknown {
    if (routeKey !== 'logs' && routeKey !== 'diagnostics') return payload;
    if (!record(payload) || payload.success === false
        || (payload.error !== undefined && payload.error !== null && payload.error !== false && payload.error !== '')) {
        throw invalid();
    }

    if (routeKey === 'logs') {
        if (!Array.isArray(payload.data) || !payload.data.every(record)) throw invalid();
        return payload;
    }

    // Laravel returns two *separate* arrays, not a generic { data: [...] } envelope.
    // Never silently fall back to an empty collection for a malformed shape.
    if (!Array.isArray(payload.failed_operations) || !payload.failed_operations.every(record)
        || !Array.isArray(payload.recent_audit) || !payload.recent_audit.every(record)) {
        throw invalid();
    }

    const failures = payload.failed_operations as Array<Record<string, unknown>>;
    const audits = payload.recent_audit as Array<Record<string, unknown>>;
    if (failures.length > 100 || audits.length > 100) throw invalid();

    const rows: Array<Record<string, unknown>> = [
        ...failures.map((row) => ({
            source: 'failed_operation',
            record_id: identifier(row.id),
            event_type: technical(row.type),
            status: 'failed',
            occurred_at: timestamp(row.failed_at ?? row.created_at),
        })),
        ...audits.map((row) => ({
            source: 'audit_event',
            record_id: identifier(row.id),
            event_type: technical(row.event),
            status: 'recorded',
            occurred_at: timestamp(row.occurred_at),
        })),
    ];

    return {
        data: rows,
        total: rows.length,
        current_page: 1,
        last_page: 1,
        meta: {
            failed_operations_count: failures.length,
            recent_audit_count: audits.length,
        },
    };
}
