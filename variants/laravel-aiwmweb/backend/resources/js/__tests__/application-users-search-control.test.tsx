import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    APPLICATION_USERS_SEARCH_OPERATION_ID,
    ApplicationUsersSearchControl,
} from '../application-users-search-control';

describe('canonical ApplicationUsers search control AIMW-SYNC-26F2BF8EE8', () => {
    it('renders the exact canonical submit control for an authorized manager', () => {
        const submit = vi.fn((event: React.FormEvent) => event.preventDefault());
        render(
            <form onSubmit={submit}>
                <ApplicationUsersSearchControl authorized />
            </form>,
        );

        const button = screen.getByRole('button', { name: 'Search' });
        expect(APPLICATION_USERS_SEARCH_OPERATION_ID).toBe('AIMW-SYNC-26F2BF8EE8');
        expect(button).toHaveAttribute('type', 'submit');
        expect(button).toHaveAttribute('data-canonical-operation', APPLICATION_USERS_SEARCH_OPERATION_ID);

        fireEvent.click(button);
        expect(submit).toHaveBeenCalledTimes(1);
    });

    it('fails closed when the UsersManage-equivalent permission is absent', () => {
        render(<ApplicationUsersSearchControl authorized={false} />);
        expect(screen.queryByRole('button', { name: 'Search' })).not.toBeInTheDocument();
    });

    it('disables duplicate activation while the authoritative query is busy', () => {
        render(<ApplicationUsersSearchControl authorized busy />);
        const button = screen.getByRole('button', { name: 'Searching…' });
        expect(button).toBeDisabled();
        expect(button).toHaveAttribute('aria-busy', 'true');
    });

    it('preserves Arabic action copy', () => {
        render(<ApplicationUsersSearchControl authorized locale="ar" />);
        expect(screen.getByRole('button', { name: 'بحث' })).toBeInTheDocument();
    });
});
