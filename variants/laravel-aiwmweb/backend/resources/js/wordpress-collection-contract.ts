/**
 * Validate remote WordPress collection GETs before React Query marks them
 * successful. An unexpected HTTP 200 payload is not evidence of zero rows:
 * reporting an empty collection would mask synchronization / API failures.
 *
 * This is read-only and grants no Connector scope or mutation authority.
 */
const wordpressCollectionRoutes = new Set([
    'posts', 'pages', 'media', 'comments', 'taxonomy',
    'categories', 'tags', 'wp-users',
]);

const isRecord = (value: unknown): value is Record<string, unknown> =>
    value !== null && typeof value === 'object' && !Array.isArray(value);

const invalidCollection = (): Error => new Error(
    'WordPress collection response was invalid. Refresh or check the site connection and runtime diagnostics.',
);

function checkPageFields(container: Record<string, unknown>): void {
    for (const field of ['total', 'current_page', 'page', 'last_page', 'lastPage']) {
        const value = container[field];
        if (value === undefined || value === null) continue;
        if (typeof value !== 'number' || !Number.isSafeInteger(value) || (field === 'total' ? value < 0 : value < 1)) {
            throw invalidCollection();
        }
    }
}

export function assertValidWordPressCollection(routeKey: string, payload: unknown): void {
    if (!wordpressCollectionRoutes.has(routeKey)) return;
    const envelope = Array.isArray(payload) ? null : payload;
    if (!Array.isArray(payload) && !isRecord(envelope)) throw invalidCollection();

    if (isRecord(envelope)) {
        if (envelope.success === false || (envelope.error !== undefined && envelope.error !== null && envelope.error !== false && envelope.error !== '')) {
            throw invalidCollection();
        }
    }

    const rows = Array.isArray(payload)
        ? payload
        : isRecord(envelope)
            ? envelope.data ?? envelope.items
            : undefined;
    if (!Array.isArray(rows) || !rows.every(isRecord)) throw invalidCollection();

    if (isRecord(envelope)) {
        checkPageFields(envelope);
        if (envelope.meta !== undefined && envelope.meta !== null) {
            if (!isRecord(envelope.meta)) throw invalidCollection();
            checkPageFields(envelope.meta);
        }
    }
}

/**
 * Keep invalid successful HTTP responses in React Query's error/retry state
 * rather than throwing while rendering or fabricating empty data.
 */
export async function readWorkspaceCollectionResponse(
    routeKey: string,
    endpoint: string,
    transport: (url: string) => Promise<unknown>,
): Promise<unknown> {
    const payload = await transport(endpoint);
    assertValidWordPressCollection(routeKey, payload);
    return payload;
}
