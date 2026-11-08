/** Bounded, pure navigation over authoritative server page metadata. */
export const SUPPORTED_PAGE_SIZES = [10, 25, 50, 100] as const;

export function visiblePaginationPages(currentPage: number, lastPage: number, maxVisible = 5): number[] {
    if (!Number.isSafeInteger(currentPage) || !Number.isSafeInteger(lastPage) || lastPage < 1) return [];
    const count = Math.max(1, Math.min(7, Math.trunc(maxVisible)));
    const center = Math.min(lastPage, Math.max(1, currentPage));
    const start = Math.max(1, Math.min(center - Math.floor(count / 2), lastPage - count + 1));
    return Array.from({ length: Math.min(count, lastPage) }, (_, index) => start + index);
}

/**
 * Never claim a total from a truncated/current-page result. Only an explicit
 * nonnegative server total is authoritative.
 */
export function authoritativeCollectionTotal(total: unknown, verified: boolean | undefined = true): number | undefined {
    // WordPress gateways can omit X-WP-Total; their page-local count is not a grand total.
    // JSON is untrusted at runtime even when the TypeScript caller expects
    // boolean. Only an omitted flag or an actual true may confirm a total.
    if (verified !== true && verified !== undefined) return undefined;
    return typeof total === 'number' && Number.isSafeInteger(total) && total >= 0 ? total : undefined;
}
