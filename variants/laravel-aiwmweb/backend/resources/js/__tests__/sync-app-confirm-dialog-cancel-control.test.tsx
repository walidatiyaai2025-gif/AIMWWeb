import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    SYNC_APP_CONFIRM_DIALOG_CANCEL_OPERATION_ID,
    SyncAppConfirmDialogCancelControl,
} from '../sync-app-confirm-dialog-cancel-control';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('AIMW-SYNC-2A8778C6EA AppConfirmDialog CancelClickedAsync', () => {
    it('closes first and then dispatches OnCancel exactly like the source callback sequence', async () => {
        const trace: string[] = [];
        const onOpenChanged = vi.fn(async (open: boolean) => {
            trace.push(`open:${open}`);
        });
        const onCancel = vi.fn(async () => {
            trace.push('cancel');
        });

        render(
            <SyncAppConfirmDialogCancelControl
                busy={false}
                cancelText="Keep editing"
                cancelAriaLabel="Cancel confirmation"
                onOpenChanged={onOpenChanged}
                onCancel={onCancel}
            />,
        );

        const button = screen.getByRole('button', { name: 'Cancel confirmation' });
        expect(SYNC_APP_CONFIRM_DIALOG_CANCEL_OPERATION_ID).toBe('AIMW-SYNC-2A8778C6EA');
        expect(button).toHaveAttribute('data-canonical-operation', SYNC_APP_CONFIRM_DIALOG_CANCEL_OPERATION_ID);
        expect(button).toHaveAttribute('data-source-handler', 'CancelClickedAsync');

        fireEvent.click(button);

        await waitFor(() => expect(trace).toEqual(['open:false', 'cancel']));
        expect(onOpenChanged).toHaveBeenCalledWith(false);
        expect(onOpenChanged).toHaveBeenCalledTimes(1);
        expect(onCancel).toHaveBeenCalledTimes(1);
    });

    it('fails closed while Busy and dispatches neither callback', () => {
        const onOpenChanged = vi.fn();
        const onCancel = vi.fn();

        render(
            <SyncAppConfirmDialogCancelControl
                busy
                onOpenChanged={onOpenChanged}
                onCancel={onCancel}
            />,
        );

        const button = screen.getByRole('button', { name: 'Cancel' });
        expect(button).toBeDisabled();
        fireEvent.click(button);
        expect(onOpenChanged).not.toHaveBeenCalled();
        expect(onCancel).not.toHaveBeenCalled();
    });

    it('suppresses duplicate dispatch while the close callback is still in flight', async () => {
        let release!: () => void;
        const onOpenChanged = vi.fn(() => new Promise<void>((resolve) => {
            release = resolve;
        }));
        const onCancel = vi.fn();

        render(
            <SyncAppConfirmDialogCancelControl
                busy={false}
                onOpenChanged={onOpenChanged}
                onCancel={onCancel}
            />,
        );

        const button = screen.getByRole('button', { name: 'Cancel' });
        fireEvent.click(button);
        fireEvent.click(button);

        expect(onOpenChanged).toHaveBeenCalledTimes(1);
        expect(onCancel).not.toHaveBeenCalled();
        await waitFor(() => expect(button).toBeDisabled());

        release();

        await waitFor(() => expect(onCancel).toHaveBeenCalledTimes(1));
        await waitFor(() => expect(button).not.toBeDisabled());
    });

    it('contains no request surface and delegates cancellation only to caller callbacks', async () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        const onOpenChanged = vi.fn();
        const onCancel = vi.fn();

        render(
            <SyncAppConfirmDialogCancelControl
                busy={false}
                onOpenChanged={onOpenChanged}
                onCancel={onCancel}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        await waitFor(() => expect(onCancel).toHaveBeenCalledTimes(1));
        expect(fetchSpy).not.toHaveBeenCalled();
    });
});
