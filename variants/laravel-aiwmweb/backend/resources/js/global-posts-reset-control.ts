export const GLOBAL_POSTS_RESET_OPERATION_ID = 'AIMW-SYNC-1A20057999';

export type GlobalPostsFilters = { search: string; status: string; perPage: number };

export function defaultGlobalPostsFilters(): GlobalPostsFilters {
    return { search: '', status: 'all', perPage: 25 };
}

// The API is an authoritative tenant-scoped read of synchronized WordPress posts.
// Never send a caller-supplied tenant, site credential, or actor identity.
export function globalPostsFilterQuery(filters: GlobalPostsFilters): string {
    const status = ['all', 'publish', 'draft', 'pending', 'future', 'private'].includes(filters.status)
        ? filters.status : 'all';
    const perPage = [10, 25, 50, 100].includes(filters.perPage) ? filters.perPage : 25;
    const params = new URLSearchParams({ page: '1', per_page: String(perPage), status });
    if (filters.search.trim()) params.set('search', filters.search.trim().slice(0, 200));
    return params.toString();
}
