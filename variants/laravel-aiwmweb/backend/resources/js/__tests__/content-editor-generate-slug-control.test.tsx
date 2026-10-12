import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    CONTENT_EDITOR_GENERATE_SLUG_OPERATION_ID,
    ContentEditorGenerateSlugControl,
    generateContentEditorSlug,
} from '../content-editor-save-control';

describe('AIMW-CONT-564636DDC9 ContentEditor GenerateSlug', () => {
    it('preserves the source GenerateSlug normalization semantics', () => {
        expect(CONTENT_EDITOR_GENERATE_SLUG_OPERATION_ID).toBe('AIMW-CONT-564636DDC9');
        expect(generateContentEditorSlug('<strong>Hello &amp; WÖRLD!</strong>')).toBe('hello-wörld');
        expect(generateContentEditorSlug('  مرحبا   بالعالم  ')).toBe('مرحبا-بالعالم');
        expect(generateContentEditorSlug('One___Two / Three')).toBe('one-two-three');
        expect(generateContentEditorSlug('---')).toBe('');
    });

    it('generates from the current title and returns the slug to the editor draft callback', () => {
        const onGenerate = vi.fn();
        render(
            <ContentEditorGenerateSlugControl
                title="Release Notes 2026"
                disabled={false}
                locale="en"
                onGenerate={onGenerate}
            />,
        );

        const button = screen.getByRole('button', { name: 'Generate' });
        expect(button).toHaveAttribute('data-canonical-operation', CONTENT_EDITOR_GENERATE_SLUG_OPERATION_ID);
        fireEvent.click(button);

        expect(onGenerate).toHaveBeenCalledTimes(1);
        expect(onGenerate).toHaveBeenCalledWith('release-notes-2026');
    });

    it('respects disabled editor state and does not fabricate a success path', () => {
        const onGenerate = vi.fn();
        render(
            <ContentEditorGenerateSlugControl
                title="عنوان عربي"
                disabled
                locale="ar"
                onGenerate={onGenerate}
            />,
        );

        const button = screen.getByRole('button', { name: 'توليد' });
        expect(button).toBeDisabled();
        fireEvent.click(button);
        expect(onGenerate).not.toHaveBeenCalled();
        expect(screen.queryByRole('status')).toBeNull();
    });
});
