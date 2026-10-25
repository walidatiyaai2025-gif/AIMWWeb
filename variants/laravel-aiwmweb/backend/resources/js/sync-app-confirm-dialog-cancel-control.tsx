import React, { useRef, useState } from 'react';

export const SYNC_APP_CONFIRM_DIALOG_CANCEL_OPERATION_ID = 'AIMW-SYNC-2A8778C6EA';

export function SyncAppConfirmDialogCancelControl({
    busy,
    cancelText = 'Cancel',
    cancelAriaLabel,
    onOpenChanged,
    onCancel,
}: {
    busy: boolean;
    cancelText?: string;
    cancelAriaLabel?: string;
    onOpenChanged: (open: boolean) => Promise<unknown> | unknown;
    onCancel: () => Promise<unknown> | unknown;
}) {
    const dispatchingRef = useRef(false);
    const [dispatching, setDispatching] = useState(false);
    const effectiveBusy = busy || dispatching;

    const cancel = async () => {
        if (busy || dispatchingRef.current) return;

        dispatchingRef.current = true;
        setDispatching(true);
        try {
            await onOpenChanged(false);
            await onCancel();
        } finally {
            dispatchingRef.current = false;
            setDispatching(false);
        }
    };

    return (
        <button
            type="button"
            className="btn"
            disabled={effectiveBusy}
            aria-label={cancelAriaLabel ?? cancelText}
            aria-busy={effectiveBusy ? 'true' : 'false'}
            data-canonical-operation={SYNC_APP_CONFIRM_DIALOG_CANCEL_OPERATION_ID}
            data-source-handler="CancelClickedAsync"
            onClick={() => void cancel()}
        >
            <span>{cancelText}</span>
        </button>
    );
}
