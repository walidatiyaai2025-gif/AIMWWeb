import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    CONTENT_EDITOR_SHOW_EDIT_OPERATION_ID,
    ContentEditorShowEditControl,
    showContentEditorEdit,
} from '../content-editor-save-control';
import { LocaleProvider } from '../i18n';

const renderControl = (viewMode: 'edit' | 'preview', onShowEdit = vi.fn()) => {
    render(
        <LocaleProvider>
            <ContentEditorShowEditControl viewMode={viewMode} onShowEdit={onShowEdit} />
        </LocaleProvider>,
    );
    return onShowEdit;
};

describe('AIMW-CONT-A5D88995F3 Content Editor ShowEdit', () => {
    it('binds the exact canonical operation and requests the source-equivalent edit transition', () => {
        const onShowEdit = renderControl('preview');
        const button = screen.getByRole('button', { name: 'Edit' });

        expect(CONTENT_EDITOR_SHOW_EDIT_OPERATION_ID).toBe('AIMW-CONT-A5D88995F3');
        expect(button).toHaveAttribute('data-canonical-operation', CONTENT_EDITOR_SHOW_EDIT_OPERATION_ID);
        expect(button).toHaveAttribute('aria-pressed', 'false');
        expect(button).not.toHaveClass('active');

        fireEvent.click(button);
        expect(onShowEdit).toHaveBeenCalledTimes(1);
        expect(showContentEditorEdit()).toBe('edit');
    });

    it('renders the active edit state deterministically without network or mutation behavior', () => {
        renderControl('edit');
        const button = screen.getByRole('button', { name: 'Edit' });

        expect(button).toHaveAttribute('aria-pressed', 'true');
        expect(button).toHaveClass('active');
    });
});
