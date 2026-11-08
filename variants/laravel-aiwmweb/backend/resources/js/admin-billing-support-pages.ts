/** Canonical billing-support grid URL. Legacy SearchAsync uses `q` on the same API. */
export function billingSupportPageUrl(endpoint: string, page: number, search: string, perPage: number): string {
    const target = new URL(endpoint, 'https://aiwm.invalid');
    const safePage = Number.isSafeInteger(page) && page >= 1 && page <= 1000000 ? page : 1;
    const safePerPage = [10, 25, 50, 100].includes(perPage) ? perPage : 25;
    target.searchParams.set('page', String(safePage));
    target.searchParams.set('per_page', String(safePerPage));
    const term = search.trim();
    if (term) target.searchParams.set('search', term.slice(0, 200));
    else target.searchParams.delete('search');
    return target.pathname + target.search;
}
