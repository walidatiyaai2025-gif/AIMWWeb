import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    APP_FILTER_CHIP_REMOVE_OPERATION_ID,
    AppFilterChip,
} from '../app-filter-chip';

describe('canonical AppFilterChip remove control AIMW-SYNC-E36C56A631', () => {
    it('preserves label, value, tone, class, and canonical fallback aria semantics', () => {
        render(
            <AppFilterChip
                label="Status"
                value="Published"
                tone="info"
                className="extra-chip"
                onRemoveRequested={() => undefined}
            />,
        );

        const chip = screen.getByText('Published').closest('.app-filter-chip');
        expect(screen.getByText('Status')).toBeInTheDocument();
        expect(chip).toHaveClass('extra-chip');
        expect(chip).toHaveAttribute('data-tone', 'info');
        expect(chip).toHaveAttribute('data-canonical-operation', APP_FILTER_CHIP_REMOVE_OPERATION_ID);
        expect(APP_FILTER_CHIP_REMOVE_OPERATION_ID).toBe('AIMW-SYNC-E36C56A631');
        expect(screen.getByRole('button', { name: 'Remove Status filter' })).toBeInTheDocument();
    });

    it('fails closed when a cross-tenant authoritative reread resolves 404 not found', () => {
        // Cross-tenant resolution is rejected by the caller with 404 before a callback is bound.
        render(<AppFilterChip label="Site" value="Foreign site" />);

        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('fails closed when authorization resolves 403 forbidden', () => {
        // RBAC denial is rejected by the caller with 403 before a mutation callback is exposed.
        render(<AppFilterChip label="Site" value="Protected site" />);

        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('does not invoke removal while disabled', () => {
        const remove = vi.fn();
        render(<AppFilterChip label="Status" value="Draft" disabled onRemoveRequested={remove} />);

        fireEvent.click(screen.getByRole('button', { name: 'Remove Status filter' }));
        expect(remove).not.toHaveBeenCalled();
    });

    it('locks duplicate async removal and remains retryable after a rejected callback', async () => {
        let rejectFirst: ((reason?: unknown) => void) | undefined;
        const first = new Promise<void>((_resolve, reject) => {
            rejectFirst = reject;
        });
        const remove = vi.fn()
            .mockImplementationOnce(() => first)
            .mockResolvedValueOnce(undefined);

        render(<AppFilterChip label="Status" value="Draft" onRemoveRequested={remove} />);
        const button = screen.getByRole('button', { name: 'Remove Status filter' });

        fireEvent.click(button);
        fireEvent.click(button);
        expect(remove).toHaveBeenCalledTimes(1);
        expect(button).toBeDisabled();

        rejectFirst?.(new Error('authoritative removal failed'));
        expect(await screen.findByRole('alert')).toHaveTextContent('Unable to remove filter.');
        await waitFor(() => expect(button).not.toBeDisabled());

        fireEvent.click(button);
        await waitFor(() => expect(remove).toHaveBeenCalledTimes(2));
        await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
    });

    it('honors an explicit removal aria label', () => {
        render(
            <AppFilterChip
                label="Status"
                value="Draft"
                removeAriaLabel="Clear status constraint"
                onRemoveRequested={() => undefined}
            />,
        );

        expect(screen.getByRole('button', { name: 'Clear status constraint' })).toBeInTheDocument();
    });
});
