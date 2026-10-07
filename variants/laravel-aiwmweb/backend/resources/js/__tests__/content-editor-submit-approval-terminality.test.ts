import { describe, expect, it } from 'vitest';
import {
    CONTENT_EDITOR_SUBMIT_APPROVAL_OPERATION_ID,
    buildContentEditorApprovalPayload,
    contentEditorApprovalEndpoint,
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
    title: 'Proposed',
    slug: 'proposed',
    content: '<p>Proposed</p>',
    excerpt: 'Proposed excerpt',
    status: 'pending',
    date_gmt: '',
    featured_media: 8,
    categories: [1],
    tags: [2],
    categories_text: '3, 7, 3',
    tags_text: '11, 12',
    template: 'wide',
    comment_status: 'closed',
    ping_status: 'closed',
    format: 'standard',
    sticky: true,
};

describe('ContentEditor SubmitForApprovalAsync terminality', () => {
    it('binds the canonical operation to the editor approval endpoint', () => {
        expect(CONTENT_EDITOR_SUBMIT_APPROVAL_OPERATION_ID).toBe('AIMW-BILL-F5686193FE');
        expect(contentEditorApprovalEndpoint('alpha team', 12, 'post', 404))
            .toBe('/api/v1/tenants/alpha%20team/sites/12/content/post/404/editor/approval');
        expect(contentEditorApprovalEndpoint('alpha', 12, 'page', 405))
            .toBe('/api/v1/tenants/alpha/sites/12/content/page/405/editor/approval');
    });

    it('fails closed for invalid tenant, site, type, and WordPress identifiers', () => {
        expect(contentEditorApprovalEndpoint('', 12, 'post', 404)).toBeNull();
        expect(contentEditorApprovalEndpoint('alpha/beta', 12, 'post', 404)).toBeNull();
        expect(contentEditorApprovalEndpoint('alpha', 0, 'post', 404)).toBeNull();
        expect(contentEditorApprovalEndpoint('alpha', 12, 'comment', 404)).toBeNull();
        expect(contentEditorApprovalEndpoint('alpha', 12, 'post', 'bad')).toBeNull();
    });

    it('submits only editable fields, optimistic baseline evidence, and the request key', () => {
        const payload = buildContentEditorApprovalPayload(
            snapshot,
            draft,
            '11111111-1111-4111-8111-111111111111',
        );

        expect(payload).toMatchObject({
            request_key: '11111111-1111-4111-8111-111111111111',
            title: 'Proposed',
            content: '<p>Proposed</p>',
            status: 'pending',
            categories: [3, 7],
            tags: [11, 12],
            expected_hash: 'a'.repeat(64),
            expected_modified_at: '2026-10-07T12:00:00Z',
            expected_version: 'v1',
        });
        expect(payload).not.toHaveProperty('tenant_id');
        expect(payload).not.toHaveProperty('site_id');
        expect(payload).not.toHaveProperty('wordpress_id');
        expect(payload).not.toHaveProperty('actor_user_id');
    });
});
