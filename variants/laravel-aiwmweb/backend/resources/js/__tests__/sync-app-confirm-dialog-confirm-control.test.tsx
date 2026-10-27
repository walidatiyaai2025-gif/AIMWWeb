import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    SYNC_APP_CONFIRM_DIALOG_CONFIRM_OPERATION_ID,
    SyncAppConfirmDialogConfirmControl,
} from '../sync-app-confirm-dialog-confirm-control';

afterEach(() => cleanup());

describe('AIMW-SYNC-9DFB5B4FF9 AppConfirmDialog ConfirmClickedAsync', () => {
    it('exposes the exact canonical marker and dispatches the caller callback once', async () => {
        let release!: () => void;
        const onConfirm = vi.fn(() => new Promise<void>((resolve) => { release = resolve; }));

        render(
            <SyncAppConfirmDialogConfirmControl
                busy={false}
                confirmText="Confirm trash"
                confirmIcon=""
                onConfirm={onConfirm}
            />,
        );

        const button = screen.getByRole('button', { name: 'Confirm trash' });
        expect(SYNC_APP_CONFIRM_DIALOG_CONFIRM_OPERATION_ID).toBe('AIMW-SYNC-9DFB5B4FF9');
        expect(button).toHaveAttribute('data-canonical-operation', SYNC_APP_CONFIRM_DIALOG_CONFIRM_OPERATION_ID);
        expect(button).toHaveAttribute('data-source-handler', 'ConfirmClickedAsync');

        fireEvent.click(button);
        fireEvent.click(button);
        expect(onConfirm).toHaveBeenCalledTimes(1);
        await waitFor(() => expect(button).toBeDisabled());

        release();
        await waitFor(() => expect(button).not.toBeDisabled());
    });

    it('fails closed while busy, disabled, or typed confirmation does not match', () => {
        const onConfirm = vi.fn();
        const view = render(
            <SyncAppConfirmDialogConfirmControl
                busy={false}
                confirmationMatches={false}
                confirmText="Delete"
                onConfirm={onConfirm}
            />,
        );

        expect(screen.getByRole('button', { name: 'Delete' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
        expect(onConfirm).not.toHaveBeenCalled();

        view.rerender(
            <SyncAppConfirmDialogConfirmControl
                busy={false}
                disabled
                confirmationMatches
                confirmText="Delete"
                onConfirm={onConfirm}
            />,
        );
        expect(screen.getByRole('button', { name: 'Delete' })).toBeDisabled();

        view.rerender(
            <SyncAppConfirmDialogConfirmControl
                busy
                confirmationMatches
                confirmText="Delete"
                busyText="Deleting..."
                onConfirm={onConfirm}
            />,
        );
        expect(screen.getByRole('button', { name: 'Delete' })).toBeDisabled();
        expect(screen.getByText('Deleting...')).toBeInTheDocument();
        expect(onConfirm).not.toHaveBeenCalled();
    });

    it('contains no request surface and delegates authoritative work to the supplied callback', () => {
        const onConfirm = vi.fn();
        render(
            <SyncAppConfirmDialogConfirmControl
                busy={false}
                confirmText="Confirm"
                onConfirm={onConfirm}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));
        expect(onConfirm).toHaveBeenCalledTimes(1);
    });
});
