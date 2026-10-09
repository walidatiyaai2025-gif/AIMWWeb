import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
    GLOBAL_SYNCHRONIZATION_REVIEW_CONFLICTS_OPERATION_ID,
    globalSynchronizationConflictReviewEndpoint,
} from '../global-synchronization-review-conflicts-control';

describe('GlobalSynchronizationWorkspace ReviewConflictsAsync terminality', () => {
    it('binds the exact canonical operation and tenant/site endpoint', () => {
        expect(GLOBAL_SYNCHRONIZATION_REVIEW_CONFLICTS_OPERATION_ID).toBe('AIMW-BILL-5887A977D7');
        expect(globalSynchronizationConflictReviewEndpoint('alpha team', 7))
            .toBe('/api/v1/tenants/alpha%20team/sites/7/conflicts');
        expect(globalSynchronizationConflictReviewEndpoint('', 7)).toBeNull();
        expect(globalSynchronizationConflictReviewEndpoint('alpha/beta', 7)).toBeNull();
        expect(globalSynchronizationConflictReviewEndpoint('alpha', 0)).toBeNull();
    });

    it('keeps review read-only and explicit about conflict evidence', () => {
        const widget = readFileSync('resources/js/global-synchronization-review-conflicts-control.tsx', 'utf8');

        expect(widget).toContain('data-review-conflicts');
        expect(widget).toContain('onClick={() => query.refetch()}');
        expect(widget).toContain("queryFn: () => apiRequest<ConflictPage>(endpoint as string)");
        expect(widget).not.toContain("method: 'POST'");
        expect(widget).not.toContain("method: 'PUT'");
        expect(widget).not.toContain("method: 'DELETE'");
        expect(widget).toContain('No resolution is executed by this review control.');
    });
});
