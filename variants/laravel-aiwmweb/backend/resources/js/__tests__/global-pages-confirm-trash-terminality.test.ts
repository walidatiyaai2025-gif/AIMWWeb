import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
    GLOBAL_PAGES_CONFIRM_TRASH_OPERATION_ID,
    buildGlobalPagesTrashPayload,
    globalPagesEndpoint,
} from '../global-pages-confirm-trash-control';

describe('GlobalPagesExplorer ConfirmTrashAsync terminality', () => {
    it('binds the exact canonical operation and tenant-global endpoint', () => {
        expect(GLOBAL_PAGES_CONFIRM_TRASH_OPERATION_ID).toBe('AIMW-BILL-C499965699');
        expect(globalPagesEndpoint('alpha team')).toBe('/api/v1/tenants/alpha%20team/global-pages');
        expect(globalPagesEndpoint('')).toBeNull();
        expect(globalPagesEndpoint('alpha/beta')).toBeNull();
    });

    it('submits only tenant-scoped selected site and WordPress page identifiers', () => {
        const payload = buildGlobalPagesTrashPayload([
            { site_id: 7, wordpress_id: 101 },
            { site_id: 8, wordpress_id: 202 },
        ]);

        expect(payload).toEqual({
            targets: [
                { site_id: 7, wordpress_id: 101 },
                { site_id: 8, wordpress_id: 202 },
            ],
        });
        expect(payload).not.toHaveProperty('tenant_id');
        expect(payload).not.toHaveProperty('user_id');
        expect(payload).not.toHaveProperty('actor_user_id');
    });

    it('requires confirmation and authoritative reread before visible success reconciliation', () => {
        const widget = readFileSync(
            new URL('../global-pages-confirm-trash-widget.tsx', import.meta.url),
            'utf8',
        );

        expect(widget).toContain('setConfirmOpen(true)');
        expect(widget).toContain('role="dialog"');
        expect(widget).toContain('mutation.mutate(selectedTargets)');
        expect(widget).toContain('await query.refetch()');
        expect(widget).toContain("result.succeeded === 0 ? 'error' : 'success'");
        expect(widget).toContain('row.status === \'trash\'');
    });
});
