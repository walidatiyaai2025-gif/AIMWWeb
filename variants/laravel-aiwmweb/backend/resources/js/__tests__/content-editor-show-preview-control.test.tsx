import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    CONTENT_EDITOR_SHOW_PREVIEW_OPERATION_ID,
    ContentEditorPreviewSurface,
    buildContentEditorPreviewDocument,
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
        const previewDocument = frame.getAttribute('srcdoc') ?? '';
        expect(previewDocument).toContain(html);
        expect(previewDocument).toContain('name="viewport"');
        expect(previewDocument).toContain('script-src \'none\'');
        expect(previewDocument).toContain('form-action \'none\'');
        expect(previewDocument).toContain('table { display: block; max-width: 100%; overflow-x: auto;');
        expect(previewDocument).toContain('img, svg, canvas, video, audio { max-width: 100%; height: auto;');
        expect(previewDocument).toContain('html lang="en" dir="ltr"');
    });

    it('uses an isolated RTL document and contains oversized media, tables and code', () => {
        const document = buildContentEditorPreviewDocument('<img src="https://example.test/image.png"><table><tr><td>Long</td></tr></table><pre>x</pre>', 'ar');
        expect(document).toContain('lang="ar" dir="rtl"');
        expect(document).toContain('default-src \'none\'');
        expect(document).toContain('object-src \'none\'');
        expect(document).toContain('overflow-wrap: anywhere');
        expect(document).toContain('overflow-x: auto;');
        expect(document).toContain('<img src="https://example.test/image.png">');
    });

    it('previews the current unsaved draft after a rerender without mutating WordPress', () => {
        const { rerender } = render(
            <LocaleProvider><ContentEditorPreviewSurface content="<p>Old draft</p>" /></LocaleProvider>,
        );
        expect(screen.getByTitle('Content HTML preview').getAttribute('srcdoc')).toContain('Old draft');
        rerender(
            <LocaleProvider><ContentEditorPreviewSurface content="<p>Updated unsaved draft</p>" /></LocaleProvider>,
        );
        const frame = screen.getByTitle('Content HTML preview');
        expect(frame.getAttribute('srcdoc')).toContain('Updated unsaved draft');
        expect(frame.getAttribute('srcdoc')).not.toContain('Old draft');
        expect(frame).toHaveAttribute('sandbox', '');
    });

    it('keeps potentially executable HTML in a script-disabled sandbox', () => {
        render(
            <LocaleProvider><ContentEditorPreviewSurface content="<script>window.parent.hacked=true</script><form action='https://example.test/collect'></form>" /></LocaleProvider>,
        );
        const frame = screen.getByTitle('Content HTML preview');
        expect(frame).toHaveAttribute('sandbox', '');
        expect(frame).toHaveAttribute('referrerpolicy', 'no-referrer');
        expect(frame.getAttribute('srcdoc')).toContain("script-src 'none'");
        expect(frame.getAttribute('srcdoc')).toContain("form-action 'none'");
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
