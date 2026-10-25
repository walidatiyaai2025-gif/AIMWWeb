import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { SITES_SAVE_OPERATION_ID } from '../sites-save-control';
import {
    SITES_SAVING_STATE_OPERATION_ID,
    SitesSavingStateControl,
} from '../sites-saving-state-control';

describe('AIMW-CONT-2752ED9351 Sites saving-state control', () => {
    it('renders the canonical idle Save state without owning the mutation', () => {
        render(
            <SitesSavingStateControl
                busy={false}
                saveOperationId={SITES_SAVE_OPERATION_ID}
            />,
        );

        const button = screen.getByRole('button', { name: 'Save' });
        expect(SITES_SAVING_STATE_OPERATION_ID).toBe('AIMW-CONT-2752ED9351');
        expect(button).toHaveAttribute('type', 'submit');
        expect(button).toHaveAttribute('data-canonical-operation', SITES_SAVE_OPERATION_ID);
        expect(button).toHaveAttribute('data-canonical-visible-operation', SITES_SAVING_STATE_OPERATION_ID);
        expect(button).toHaveAttribute('data-save-state', 'idle');
        expect(button).toHaveAttribute('aria-busy', 'false');
        expect(button).not.toBeDisabled();
    });

    it('renders Saving and locks duplicate submit while authoritative save is in flight', () => {
        render(
            <SitesSavingStateControl
                busy
                saveOperationId={SITES_SAVE_OPERATION_ID}
            />,
        );

        const button = screen.getByRole('button', { name: 'Saving…' });
        expect(button).toBeDisabled();
        expect(button).toHaveAttribute('aria-busy', 'true');
        expect(button).toHaveAttribute('data-save-state', 'saving');
        expect(button).toHaveAttribute('data-canonical-operation', SITES_SAVE_OPERATION_ID);
    });

    it('preserves Arabic idle and busy labels', () => {
        const { rerender } = render(
            <SitesSavingStateControl
                busy={false}
                locale="ar"
                saveOperationId={SITES_SAVE_OPERATION_ID}
            />,
        );
        expect(screen.getByRole('button', { name: 'حفظ' })).not.toBeDisabled();

        rerender(
            <SitesSavingStateControl
                busy
                locale="ar"
                saveOperationId={SITES_SAVE_OPERATION_ID}
            />,
        );
        expect(screen.getByRole('button', { name: 'جارٍ الحفظ…' })).toBeDisabled();
    });
});
