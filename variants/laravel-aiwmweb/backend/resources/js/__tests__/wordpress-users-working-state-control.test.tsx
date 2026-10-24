import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    WORDPRESS_USERS_WORKING_STATE_OPERATION_ID,
    WordPressUsersWorkingStateControl,
} from '../wordpress-users-working-state-control';

describe('AIMW-CONT-346657AD9D WordPress Users working-state control', () => {
    it('renders the canonical idle Save state without owning a mutation', () => {
        render(<WordPressUsersWorkingStateControl busy={false} />);

        const button = screen.getByRole('button', { name: 'Save' });
        expect(WORDPRESS_USERS_WORKING_STATE_OPERATION_ID).toBe('AIMW-CONT-346657AD9D');
        expect(button).toHaveAttribute('type', 'submit');
        expect(button).toHaveAttribute('data-canonical-operation', WORDPRESS_USERS_WORKING_STATE_OPERATION_ID);
        expect(button).toHaveAttribute('aria-busy', 'false');
        expect(button).not.toBeDisabled();
    });

    it('renders Saving and locks duplicate submit while the authoritative save is in flight', () => {
        render(<WordPressUsersWorkingStateControl busy />);

        const button = screen.getByRole('button', { name: 'Saving…' });
        expect(button).toBeDisabled();
        expect(button).toHaveAttribute('aria-busy', 'true');
    });

    it('preserves the Arabic idle and busy labels', () => {
        const { rerender } = render(<WordPressUsersWorkingStateControl busy={false} locale="ar" />);
        expect(screen.getByRole('button', { name: 'حفظ' })).not.toBeDisabled();

        rerender(<WordPressUsersWorkingStateControl busy locale="ar" />);
        expect(screen.getByRole('button', { name: 'جارٍ الحفظ…' })).toBeDisabled();
    });
});
