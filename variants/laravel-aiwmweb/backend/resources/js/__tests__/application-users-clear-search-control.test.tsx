import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    APPLICATION_USERS_CLEAR_SEARCH_OPERATION_ID,
    ApplicationUsersClearSearchControl,
} from '../application-users-clear-search-control';

describe('canonical ApplicationUsers clear-search control AIMW-SYNC-F135B261B2', () => {
    it('renders the exact canonical control only for a nonblank authorized search', () => {
        render(
            <ApplicationUsersClearSearchControl
                searchValue="walid"
                authorized
                onClearRequested={() => undefined}
            />,
        );

        const button = screen.getByRole('button', { name: 'Clear' });
        expect(APPLICATION_USERS_CLEAR_SEARCH_OPERATION_ID).toBe('AIMW-SYNC-F135B261B2');
        expect(button).toHaveAttribute('data-canonical-operation', APPLICATION_USERS_CLEAR_SEARCH_OPERATION_ID);
    });

    it('fails closed for blank search state or missing members-manage authorization', () => {
        const { rerender } = render(
            <ApplicationUsersClearSearchControl
                searchValue="walid"
                authorized={false}
                onClearRequested={() => undefined}
            />,
        );
        expect(screen.queryByRole('button', { name: 'Clear' })).not.toBeInTheDocument();

        rerender(
            <ApplicationUsersClearSearchControl
                searchValue="   "
                authorized
                onClearRequested={() => undefined}
            />,
        );
        expect(screen.queryByRole('button', { name: 'Clear' })).not.toBeInTheDocument();
    });

    it('suppresses duplicate clear requests while an authoritative reread is in flight', async () => {
        let resolveClear: (() => void) | undefined;
        const pending = new Promise<void>((resolve) => { resolveClear = resolve; });
        const clear = vi.fn(() => pending);

        render(
            <ApplicationUsersClearSearchControl
                searchValue="operator"
                authorized
                onClearRequested={clear}
            />,
        );

        const button = screen.getByRole('button', { name: 'Clear' });
        fireEvent.click(button);
        fireEvent.click(button);
        expect(clear).toHaveBeenCalledTimes(1);
        expect(button).toBeDisabled();

        resolveClear?.();
        await waitFor(() => expect(button).not.toBeDisabled());
    });

    it('treats a cross-tenant authoritative 404 as failure and remains retryable', async () => {
        // tenant.context rejects a foreign/cross-tenant tenant selection with 404 before data is accepted.
        const clear = vi.fn()
            .mockRejectedValueOnce(new Error('404 cross-tenant tenant not found'))
            .mockResolvedValueOnce(undefined);

        render(
            <ApplicationUsersClearSearchControl
                searchValue="operator"
                authorized
                onClearRequested={clear}
            />,
        );

        const button = screen.getByRole('button', { name: 'Clear' });
        fireEvent.click(button);
        expect(await screen.findByRole('alert')).toHaveTextContent('authoritative reload failed');
        await waitFor(() => expect(button).not.toBeDisabled());

        fireEvent.click(button);
        await waitFor(() => expect(clear).toHaveBeenCalledTimes(2));
        await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
    });

    it('respects an externally busy authoritative query and preserves Arabic copy', () => {
        const clear = vi.fn();
        render(
            <ApplicationUsersClearSearchControl
                searchValue="operator"
                authorized
                busy
                locale="ar"
                onClearRequested={clear}
            />,
        );

        const button = screen.getByRole('button', { name: 'مسح' });
        expect(button).toBeDisabled();
        fireEvent.click(button);
        expect(clear).not.toHaveBeenCalled();
    });
});
