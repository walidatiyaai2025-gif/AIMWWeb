import React, { useRef, useState } from 'react';

export const SYNC_APP_CONFIRM_DIALOG_CONFIRM_OPERATION_ID = 'AIMW-SYNC-9DFB5B4FF9';

export function SyncAppConfirmDialogConfirmControl({
    busy,
    disabled = false,
    confirmationMatches = true,
    confirmText = 'Confirm',
    busyText,
    confirmIcon = '!',
    confirmVariant = 'danger',
    confirmAriaLabel,
    onConfirm,
}: {
    busy: boolean;
    disabled?: boolean;
    confirmationMatches?: boolean;
    confirmText?: string;
    busyText?: string;
    confirmIcon?: string;
    confirmVariant?: string;
    confirmAriaLabel?: string;
    onConfirm: () => Promise<unknown> | unknown;
}) {
    const dispatchingRef = useRef(false);
    const [dispatching, setDispatching] = useState(false);
    const effectiveBusy = busy || dispatching;
    const canConfirm = !effectiveBusy && !disabled && confirmationMatches;

    const confirm = async () => {
        if (busy || disabled || !confirmationMatches || dispatchingRef.current) return;

        dispatchingRef.current = true;
        setDispatching(true);
        try {
            await onConfirm();
        } finally {
            dispatchingRef.current = false;
            setDispatching(false);
        }
    };

    return (
        <button
            type="button"
            className={`btn ${confirmVariant}`.trim()}
            disabled={!canConfirm}
            aria-label={confirmAriaLabel ?? confirmText}
            aria-busy={effectiveBusy ? 'true' : 'false'}
            data-canonical-operation={SYNC_APP_CONFIRM_DIALOG_CONFIRM_OPERATION_ID}
            data-source-handler="ConfirmClickedAsync"
            onClick={() => void confirm()}
        >
            {confirmIcon ? <span aria-hidden="true">{confirmIcon}</span> : null}
            <span>{effectiveBusy ? (busyText ?? confirmText) : confirmText}</span>
        </button>
    );
}
