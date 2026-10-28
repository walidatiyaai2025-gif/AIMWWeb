import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { GLOBAL_POSTS_REQUEST_TRASH_OPERATION_ID, canRequestGlobalPostsTrash } from '../global-posts-request-trash-control';

describe('Global Posts RequestTrashAsync / #947', () => {
    it('exposes the exact canonical visible-control operation', () => {
        expect(GLOBAL_POSTS_REQUEST_TRASH_OPERATION_ID).toBe('AIMW-SYNC-476CDB769B');
    });
    it('rejects selection-free, oversized, unauthorized and busy requests before opening confirmation', () => {
        expect(canRequestGlobalPostsTrash(0, true, false)).toBe(false);
        expect(canRequestGlobalPostsTrash(101, true, false)).toBe(false);
        expect(canRequestGlobalPostsTrash(1, false, false)).toBe(false);
        expect(canRequestGlobalPostsTrash(1, true, true)).toBe(false);
        expect(canRequestGlobalPostsTrash(1, true, false)).toBe(true);
    });
    it('opens an Arabic/English confirmation but does not make an external call until explicit confirm', () => {
        const source = readFileSync('resources/js/global-posts-confirm-trash-widget.tsx', 'utf8');
        expect(source).toContain('data-request-operation={GLOBAL_POSTS_REQUEST_TRASH_OPERATION_ID}');
        expect(source).toContain('canRequestGlobalPostsTrash(selectedTargets.length, canEdit, busy)');
        expect(source).toContain('setConfirmOpen(true)');
        expect(source).toContain('role="dialog"');
        expect(source).toContain("locale === 'ar' ? 'نقل المحدد لسلة المهملات' : 'Move selected to trash'");
        expect(source).toContain("locale === 'ar' ? 'تأكيد النقل' : 'Confirm trash'");
        expect(source).toContain('onClick={() => mutation.mutate(selectedTargets)}');
        // The request is a safe, local state change, not a remote success.
        const handler = readFileSync('resources/js/global-posts-request-trash-control.ts', 'utf8');
        expect(handler).not.toContain('apiRequest(');
        expect(handler).not.toContain('fetch(');
        expect(source).toContain('await query.refetch()');
    });
});
