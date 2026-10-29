import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
    GLOBAL_PAGES_PUBLISH_SELECTED_OPERATION_ID,
    buildGlobalPagesPublishPayload,
    globalPagesPublishEndpoint,
} from '../global-pages-publish-selected-control';

describe('GlobalPagesExplorer PublishSelectedAsync terminality', () => {
    it('binds the exact canonical operation and tenant-global publish endpoint', () => {
        expect(GLOBAL_PAGES_PUBLISH_SELECTED_OPERATION_ID).toBe('AIMW-SYNC-ECAB44A389');
        expect(globalPagesPublishEndpoint('alpha team')).toBe('/api/v1/tenants/alpha%20team/global-pages/publish');
        expect(globalPagesPublishEndpoint('')).toBeNull();
        expect(globalPagesPublishEndpoint('alpha/beta')).toBeNull();
    });

    it('submits only selected site and WordPress identifiers', () => {
        const payload = buildGlobalPagesPublishPayload([
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

    it('wires publish into the existing global-post selection without duplicating the table', () => {
        const widget = readFileSync('resources/js/global-pages-confirm-trash-widget.tsx', 'utf8');

        expect(widget).toContain('GLOBAL_PAGES_PUBLISH_SELECTED_OPERATION_ID');
        expect(widget).toContain('publishMutation.mutate(selectedTargets)');
        expect(widget).toContain('buildGlobalPagesPublishPayload(targets)');
        expect(widget).toContain('setSelected(new Set())');
        expect(widget).toContain('result.site_jobs');
        expect(widget.match(/<table className="data-table">/g)).toHaveLength(1);
    });
});
