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
        expect(widget).toContain('globalPostsFilterQuery(filters)');
    });
});
