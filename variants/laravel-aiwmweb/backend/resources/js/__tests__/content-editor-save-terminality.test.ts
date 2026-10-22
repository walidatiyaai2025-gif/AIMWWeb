import { describe, expect, it } from 'vitest';
import {
    CONTENT_EDITOR_SAVE_OPERATION_ID,
    buildContentEditorSavePayload,
    contentEditorEndpoint,
    type ContentEditorDraft,
    type ContentEditorSnapshot,
} from '../content-editor-save-control';

const snapshot: ContentEditorSnapshot = {
    id: 9,
    wordpress_id: 404,
    type: 'post',
    title: 'Original',
    slug: 'original',
    content: '<p>Original</p>',
    excerpt: 'Original excerpt',
    status: 'draft',
    date_gmt: '2026-10-07T12:00:00Z',
    featured_media: 3,
    categories: [1],
    tags: [2],
    template: '',
    comment_status: 'open',
    ping_status: 'open',
    format: 'standard',
    sticky: false,
    expected_hash: 'a'.repeat(64),
    expected_modified_at: '2026-10-07T12:00:00Z',
    expected_version: 'v1',
};

const draft: ContentEditorDraft = {
    title: 'Updated',
    slug: 'updated',
    content: '<p>Updated</p>',
    excerpt: 'Updated excerpt',
    status: 'publish',
    date_gmt: '2026-10-07T15:30',
    featured_media: 8,
    categories: [1],
    tags: [2],
    categories_text: '3, 7, 3, bad',
    tags_text: '11, 12',
    template: 'wide',
    comment_status: 'closed',
    ping_status: 'closed',
    format: 'standard',
    sticky: true,
};

describe('ContentEditor SaveAsync terminality', () => {
    it('binds the canonical operation to the active tenant/site/WordPress editor route', () => {
        expect(CONTENT_EDITOR_SAVE_OPERATION_ID).toBe('AIMW-BILL-42F7590F00');
        expect(contentEditorEndpoint('alpha team', 12, 'post', 404))
            .toBe('/api/v1/tenants/alpha%20team/sites/12/content/post/404/editor');
        expect(contentEditorEndpoint('alpha', 12, 'page', 405))
            .toBe('/api/v1/tenants/alpha/sites/12/content/page/405/editor');
    });

    it('fails closed for invalid ownership and route identifiers', () => {
        expect(contentEditorEndpoint('', 12, 'post', 404)).toBeNull();
        expect(contentEditorEndpoint('alpha/beta', 12, 'post', 404)).toBeNull();
        expect(contentEditorEndpoint('alpha', 0, 'post', 404)).toBeNull();
        expect(contentEditorEndpoint('alpha', 12, 'comment', 404)).toBeNull();
        expect(contentEditorEndpoint('alpha', 12, 'post', 'not-a-number')).toBeNull();
    });

    it('sends only editor fields plus optimistic WordPress version evidence', () => {
        const payload = buildContentEditorSavePayload(snapshot, draft);

        expect(payload).toMatchObject({
            title: 'Updated',
            slug: 'updated',
            content: '<p>Updated</p>',
            excerpt: 'Updated excerpt',
            status: 'publish',
            featured_media: 8,
            categories: [3, 7],
            tags: [11, 12],
            template: 'wide',
            comment_status: 'closed',
            ping_status: 'closed',
            format: 'standard',
            sticky: true,
            expected_hash: 'a'.repeat(64),
            expected_modified_at: '2026-10-07T12:00:00Z',
            expected_version: 'v1',
        });
        expect(payload.date_gmt).toMatch(/^2026-10-07T/);
        expect(payload).not.toHaveProperty('tenant_id');
        expect(payload).not.toHaveProperty('site_id');
        expect(payload).not.toHaveProperty('wordpress_id');
        expect(payload).not.toHaveProperty('actor_user_id');
    });
});
