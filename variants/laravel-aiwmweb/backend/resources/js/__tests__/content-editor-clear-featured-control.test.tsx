import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    clearContentEditorFeaturedMedia,
    CONTENT_EDITOR_CLEAR_FEATURED_OPERATION_ID,
    ContentEditorClearFeaturedControl,
    type ContentEditorDraft,
} from '../content-editor-save-control';

const draft: ContentEditorDraft = {
    title: 'Post',
    slug: 'post',
    content: '<p>Body</p>',
    excerpt: '',
    status: 'draft',
    date_gmt: '',
    featured_media: 88,
    categories: [],
    tags: [],
    categories_text: '',
    tags_text: '',
    template: '',
    comment_status: 'open',
    ping_status: 'open',
    format: 'standard',
    sticky: false,
};

describe('AIMW-CONT-A6867078EA ContentEditor ClearFeatured', () => {
    it('binds the exact canonical operation and clears only the local featured-media selection', () => {
        const cleared = clearContentEditorFeaturedMedia(draft);
        expect(CONTENT_EDITOR_CLEAR_FEATURED_OPERATION_ID).toBe('AIMW-CONT-A6867078EA');
        expect(cleared.featured_media).toBe(0);
        expect(cleared).not.toBe(draft);
        expect({ ...cleared, featured_media: draft.featured_media }).toEqual(draft);
    });

    it('renders only when a featured image is selected and invokes the local clear callback', () => {
        const onClear = vi.fn();
        const { rerender } = render(
            <ContentEditorClearFeaturedControl featuredMediaId={88} disabled={false} locale="en" onClear={onClear} />,
        );
        const button = screen.getByRole('button', { name: 'Remove featured image' });
        expect(button).toHaveAttribute('data-canonical-operation', CONTENT_EDITOR_CLEAR_FEATURED_OPERATION_ID);
        fireEvent.click(button);
        expect(onClear).toHaveBeenCalledTimes(1);

        rerender(<ContentEditorClearFeaturedControl featuredMediaId={0} disabled={false} locale="en" onClear={onClear} />);
        expect(screen.queryByRole('button', { name: 'Remove featured image' })).toBeNull();
    });

    it('respects disabled editor state and exposes no fake success path', () => {
        const onClear = vi.fn();
        render(<ContentEditorClearFeaturedControl featuredMediaId={88} disabled locale="ar" onClear={onClear} />);
        const button = screen.getByRole('button', { name: 'إزالة الصورة البارزة' });
        expect(button).toBeDisabled();
        fireEvent.click(button);
        expect(onClear).not.toHaveBeenCalled();
    });
});
