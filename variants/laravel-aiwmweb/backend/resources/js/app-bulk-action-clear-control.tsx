import React from 'react';

export const APP_BULK_ACTION_CLEAR_OPERATION_ID = 'AIMW-SYNC-CDE0C219B5';

/**
 * Mirrors AppBulkActionBar.ClearAsync from the current product:
 * the control only exists for a non-empty selection with a clear callback,
 * is unavailable while busy, and publishes local clear intent without a request.
 */
export function AppBulkActionClearControl({
    selectedCount,
    busy = false,
    clearText = 'Clear',
    clearAriaLabel,
    onClear,
    className = 'btn',
    children,
}: {
    selectedCount: number;
    busy?: boolean;
    clearText?: string;
    clearAriaLabel?: string;
    onClear?: () => void | Promise<void>;
    className?: string;
    children?: React.ReactNode;
}) {
    if (selectedCount <= 0 || !onClear) return null;

    if (children) {
        return (
            <span
                className="app-bulk-action-clear-wrapper"
                data-canonical-operation={APP_BULK_ACTION_CLEAR_OPERATION_ID}
                data-source-handler="ClearAsync"
                aria-busy={busy ? 'true' : 'false'}
            >
                {children}
            </span>
        );
    }

    return (
        <button
            type="button"
            className={className}
            data-canonical-operation={APP_BULK_ACTION_CLEAR_OPERATION_ID}
            data-source-handler="ClearAsync"
            aria-label={clearAriaLabel ?? clearText}
            disabled={busy}
            onClick={() => {
                if (!busy) void onClear();
            }}
        >
            {clearText}
        </button>
    );
}
