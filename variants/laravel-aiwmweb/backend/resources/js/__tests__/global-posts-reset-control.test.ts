import { readFileSync } from 'node:fs';
import { describe, it, expect } from 'vitest';
import { GLOBAL_POSTS_RESET_OPERATION_ID, defaultGlobalPostsFilters, globalPostsFilterQuery } from '../global-posts-reset-control';

describe('Global Posts ResetAsync / #944', () => {
    it('binds the canonical operation and initial filters to the authorized GET', () => {
        expect(GLOBAL_POSTS_RESET_OPERATION_ID).toBe('AIMW-SYNC-1A20057999');
        expect(globalPostsFilterQuery(defaultGlobalPostsFilters())).toBe('page=1&per_page=25&status=all');
    });
    it('resets text, status, page size and page without caller identity', () => {
        const changed = { search: 'مقال & slug', status: 'pending', perPage: 100 };
        expect(globalPostsFilterQuery(changed)).toContain('status=pending');
        expect(globalPostsFilterQuery(changed)).toContain('page=1');
        const reset = defaultGlobalPostsFilters();
        expect(reset).toEqual({ search: '', status: 'all', perPage: 25 });
        expect(globalPostsFilterQuery(reset)).not.toContain('tenant_id');
        expect(globalPostsFilterQuery(reset)).not.toContain('user_id');
        expect(globalPostsFilterQuery({ search: 'x', status: 'injected', perPage: 999 })).toContain('per_page=25&status=all');
    });
    it('wires a real reset button and Arabic UI to query invalidation and state reset', () => {
        const widget = readFileSync('resources/js/global-posts-confirm-trash-widget.tsx', 'utf8');
        expect(widget).toContain('data-canonical-operation={GLOBAL_POSTS_RESET_OPERATION_ID}');
        expect(widget).toContain('setFilters(defaultGlobalPostsFilters())');
        expect(widget).toContain('setSelected(new Set())');
        expect(widget).toContain('queryClient.invalidateQueries');
        expect(widget).toContain("locale === 'ar' ? 'إعادة ضبط' : 'Reset'");
        expect(widget).toContain('globalPostsFilterQuery(filters, page)');
        expect(widget).toContain('setPage(1)');
        expect(widget).toContain('setConfirmOpen(false)');
    });
    it('uses bounded server pages, never losing the tenant filter or inventing a grand total', () => {
        const filters = { search: 'alpha & beta', status: 'publish', perPage: 10 };
        expect(globalPostsFilterQuery(filters, 2)).toBe('page=2&per_page=10&status=publish&search=alpha+%26+beta');
        expect(globalPostsFilterQuery(filters, Number.MAX_SAFE_INTEGER)).toContain('page=1');
        expect(globalPostsFilterQuery(filters, 0)).toContain('page=1');
        expect(globalPostsFilterQuery(filters, 1000000)).toContain('page=1000000');

        const widget = readFileSync('resources/js/global-posts-confirm-trash-widget.tsx', 'utf8');
        expect(widget).toContain('hasAuthoritativePage');
        expect(widget).toContain('lastPage={pageMeta!.last_page}');
        expect(widget).toContain('setPage(next)');
        expect(widget).toContain('setSelected(new Set())');
        expect(widget).toContain('setConfirmOpen(false)');
    });
});
