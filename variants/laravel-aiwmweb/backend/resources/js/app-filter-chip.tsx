import React, { useState } from 'react';

export const APP_FILTER_CHIP_REMOVE_OPERATION_ID = 'AIMW-SYNC-E36C56A631';

export type AppFilterChipProps = {
    label?: string | null;
    value: string;
    tone?: string;
    removeAriaLabel?: string | null;
    className?: string;
    disabled?: boolean;
    onRemoveRequested?: () => void | Promise<void>;
};

/**
 * Shared filter chip adapted from the canonical AppFilterChip Razor component.
 *
 * The component deliberately owns no tenant identifier, resource identifier, route,
 * fetch call, or persistence. A caller may bind onRemoveRequested only after its
 * authoritative tenant-scoped reread and permission check succeed. Omitting the
 * callback is therefore the fail-closed state: no mutation control is rendered.
 */
export function AppFilterChip({
    label,
    value,
    tone = 'neutral',
    removeAriaLabel,
    className = '',
    disabled = false,
    onRemoveRequested,
}: AppFilterChipProps) {
    const [isRemoving, setIsRemoving] = useState(false);
    const [removeError, setRemoveError] = useState<string | null>(null);
    const renderedLabel = typeof label === 'string' && label.trim() !== '' ? label : null;
    const canRemove = typeof onRemoveRequested === 'function';
    const classes = ['app-filter-chip', className].filter(Boolean).join(' ');

    const removeAsync = async (): Promise<void> => {
        if (disabled || isRemoving || !onRemoveRequested) {
            return;
        }

        setRemoveError(null);
        setIsRemoving(true);
        try {
            await onRemoveRequested();
        } catch {
            // Keep the chip authoritative and retryable; never pretend persistence succeeded.
            setRemoveError('Unable to remove filter.');
        } finally {
            setIsRemoving(false);
        }
    };

    return (
        <span
            className={classes}
            data-tone={tone}
            data-canonical-operation={APP_FILTER_CHIP_REMOVE_OPERATION_ID}
        >
            {renderedLabel ? <span className="app-filter-chip__label">{renderedLabel}</span> : null}
            <strong className="app-filter-chip__value">{value}</strong>
            {canRemove ? (
                <button
                    type="button"
                    className="app-filter-chip__remove"
                    disabled={disabled || isRemoving}
                    aria-label={removeAriaLabel ?? `Remove ${label ?? ''} filter`}
                    onClick={() => void removeAsync()}
                >
                    ×
                </button>
            ) : null}
            {removeError ? (
                <span className="app-filter-chip__error" role="alert">
                    {removeError}
                </span>
            ) : null}
        </span>
    );
}
