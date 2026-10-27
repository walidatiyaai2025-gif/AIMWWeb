import React from 'react';

export const APP_DIALOG_CLOSE_OPERATION_ID = 'AIMW-SYNC-ABE31C5C4E';

/** AppDialog.CloseAsync publishes local close intent; it never submits the form. */
export function AppDialogCloseControl({ onClose, showCloseButton = true, ariaLabel = 'Close dialog' }: {
    onClose: () => void;
    showCloseButton?: boolean;
    ariaLabel?: string;
}) {
    if (!showCloseButton) return null;
    return (
        <button type="button" className="icon-button app-dialog__close" data-a11y-close
            data-canonical-operation={APP_DIALOG_CLOSE_OPERATION_ID}
            data-source-handler="CloseAsync" onClick={onClose} aria-label={ariaLabel}>×</button>
    );
}
