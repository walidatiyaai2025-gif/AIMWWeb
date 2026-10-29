import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    CONTENT_EDITOR_SHOW_PREVIEW_OPERATION_ID,
    ContentEditorPreviewSurface,
    ContentEditorShowPreviewControl,
    showContentEditorPreview,
} from '../content-editor-save-control';
import { LocaleProvider } from '../i18n';

function renderControl(viewMode: 'edit' | 'preview', onShowPreview = vi.fn()) {
    render(
        <LocaleProvider>
            <ContentEditorShowPreviewControl viewMode={viewMode} onShowPreview={onShowPreview} />
        </LocaleProvider>,
    );
    return onShowPreview;
}

describe('AIMW-CONT-F57B1E1503 Content Editor ShowPreview', () => {
    it('binds the exact canonical operation and requests the source-equivalent preview transition', () => {
        const onShowPreview = renderControl('edit');
        const button = screen.getByRole('button', { name: 'Preview' });

        expect(CONTENT_EDITOR_SHOW_PREVIEW_OPERATION_ID).toBe('AIMW-CONT-F57B1E1503');
        expect(button).toHaveAttribute('data-canonical-operation', CONTENT_EDITOR_SHOW_PREVIEW_OPERATION_ID);
        expect(button).toHaveAttribute('aria-pressed', 'false');
        expect(button).not.toHaveClass('active');

        fireEvent.click(button);
        expect(onShowPreview).toHaveBeenCalledTimes(1);
        expect(showContentEditorPreview()).toBe('preview');
    });

    it('renders the active preview state deterministically', () => {
        renderControl('preview');
        const button = screen.getByRole('button', { name: 'Preview' });

        expect(button).toHaveAttribute('aria-pressed', 'true');
        expect(button).toHaveClass('active');
    });

    it('renders the current draft HTML in a sandboxed preview document', () => {
        const html = '<h1>Authoritative title</h1><p>Current draft body</p>';
        render(
            <LocaleProvider>
                <ContentEditorPreviewSurface content={html} />
            </LocaleProvider>,
        );

        const frame = screen.getByTitle('Content HTML preview');
        expect(frame).toHaveAttribute('sandbox', '');
        expect(frame).toHaveAttribute('referrerpolicy', 'no-referrer');
        expect(frame).toHaveAttribute('srcdoc', html);
    });

    it('renders an explicit empty preview state instead of fake content', () => {
        render(
            <LocaleProvider>
                <ContentEditorPreviewSurface content="   " />
            </LocaleProvider>,
        );

        expect(screen.getByRole('status')).toHaveTextContent('There is no content to preview.');
        expect(screen.queryByTitle('Content HTML preview')).not.toBeInTheDocument();
    });
});
