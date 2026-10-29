import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { GLOBAL_PAGES_REQUEST_TRASH_OPERATION_ID, canRequestGlobalPagesTrash } from '../global-pages-request-trash-control';

describe('Global Pages RequestTrashAsync / #940', () => {
    it('binds the one canonical visible-control operation', () => {
        expect(GLOBAL_PAGES_REQUEST_TRASH_OPERATION_ID).toBe('AIMW-SYNC-E3EB27C678');
    });

    it('rejects empty, oversized, invalid, unauthorized, or in-flight selections', () => {
        expect(canRequestGlobalPagesTrash(0, true, false)).toBe(false);
        expect(canRequestGlobalPagesTrash(101, true, false)).toBe(false);
        expect(canRequestGlobalPagesTrash(-1, true, false)).toBe(false);
        expect(canRequestGlobalPagesTrash(1.5, true, false)).toBe(false);
        expect(canRequestGlobalPagesTrash(1, false, false)).toBe(false);
        expect(canRequestGlobalPagesTrash(1, true, true)).toBe(false);
        expect(canRequestGlobalPagesTrash(1, true, false)).toBe(true);
        expect(canRequestGlobalPagesTrash(100, true, false)).toBe(true);
    });

    it('opens a localized confirmation without mutating WordPress until explicit confirmation', () => {
        const widget = readFileSync('resources/js/global-pages-confirm-trash-widget.tsx', 'utf8');
        const control = readFileSync('resources/js/global-pages-request-trash-control.ts', 'utf8');
        expect(widget).toContain('data-request-operation={GLOBAL_PAGES_REQUEST_TRASH_OPERATION_ID}');
        expect(widget).toContain('canRequestGlobalPagesTrash(selectedTargets.length, canEdit, busy)');
        expect(widget).toContain('setConfirmOpen(true)');
        expect(widget).toContain('role="dialog"');
        expect(widget).toContain("locale === 'ar' ? 'نقل المحدد لسلة المهملات' : 'Move selected to trash'");
        expect(widget).toContain('SyncAppConfirmDialogConfirmControl');
        expect(widget).toContain('mutation.mutateAsync(selectedTargets)');
        expect(widget).toContain('await query.refetch()');
        expect(control).not.toContain('apiRequest(');
        expect(control).not.toContain('fetch(');
    });
});
