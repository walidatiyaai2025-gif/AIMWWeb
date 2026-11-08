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
export function authoritativeCollectionTotal(total: unknown): number | undefined {
    return typeof total === 'number' && Number.isSafeInteger(total) && total >= 0 ? total : undefined;
}
