import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    CONTENT_EDITOR_SECONDARY_SAVE_OPERATION_ID,
    ContentEditorSecondarySaveControl,
} from '../content-editor-secondary-save-control';

describe('AIMW-CONT-289CEEF3BA secondary Content Editor Save control', () => {
    it('renders a real form submit bound only to the canonical secondary control operation', () => {
        render(
            <ContentEditorSecondarySaveControl
                saving={false}
                submittingApproval={false}
                titleValid
                locale="en"
            />,
        );

        const button = screen.getByRole('button', { name: 'Save to WordPress' });
        expect(CONTENT_EDITOR_SECONDARY_SAVE_OPERATION_ID).toBe('AIMW-CONT-289CEEF3BA');
        expect(button).toHaveAttribute('type', 'submit');
        expect(button).toHaveAttribute('data-canonical-operation', CONTENT_EDITOR_SECONDARY_SAVE_OPERATION_ID);
        expect(button).not.toBeDisabled();
    });

    it('shows Saving and suppresses duplicate submit while the existing save is in flight', () => {
        render(
            <ContentEditorSecondarySaveControl
                saving
                submittingApproval={false}
                titleValid
                locale="en"
            />,
        );

        expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();
    });

    it('stays disabled during approval submission and when the required title is invalid', () => {
        const { rerender } = render(
            <ContentEditorSecondarySaveControl
                saving={false}
                submittingApproval
                titleValid
                locale="en"
            />,
        );
        expect(screen.getByRole('button', { name: 'Save to WordPress' })).toBeDisabled();

        rerender(
            <ContentEditorSecondarySaveControl
                saving={false}
                submittingApproval={false}
                titleValid={false}
                locale="en"
            />,
        );
        expect(screen.getByRole('button', { name: 'Save to WordPress' })).toBeDisabled();
    });

    it('preserves Arabic idle and busy labels', () => {
        const { rerender } = render(
            <ContentEditorSecondarySaveControl
                saving={false}
                submittingApproval={false}
                titleValid
                locale="ar"
            />,
        );
        expect(screen.getByRole('button', { name: 'حفظ إلى WordPress' })).not.toBeDisabled();

        rerender(
            <ContentEditorSecondarySaveControl
                saving
                submittingApproval={false}
                titleValid
                locale="ar"
            />,
        );
        expect(screen.getByRole('button', { name: 'جارٍ الحفظ…' })).toBeDisabled();
    });
});
