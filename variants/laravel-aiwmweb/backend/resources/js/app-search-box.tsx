import React from 'react';

export const APP_SEARCH_BOX_OPERATION_ID = 'AIMW-SYNC-1778E3F6B5';

export function AppSearchBox({
    id,
    value,
    onValueChange,
    placeholder,
    ariaLabel,
    clearAriaLabel = 'Clear search',
    busyText = 'Searching',
    autoComplete = 'off',
    className = '',
    disabled = false,
    busy = false,
    showClearButton = true,
}: {
    id?: string;
    value: string;
    onValueChange: (value: string) => void;
    placeholder?: string;
    ariaLabel?: string;
    clearAriaLabel?: string;
    busyText?: string;
    autoComplete?: string;
    className?: string;
    disabled?: boolean;
    busy?: boolean;
    showClearButton?: boolean;
}) {
    return (
        <div
            className={`app-search-box ${className}`.trim()}
            aria-busy={busy ? 'true' : 'false'}
            data-canonical-operation={APP_SEARCH_BOX_OPERATION_ID}
        >
            <span className="app-search-box__icon" aria-hidden="true">⌕</span>
            <input
                id={id}
                type="search"
                value={value}
                placeholder={placeholder}
                aria-label={ariaLabel}
                autoComplete={autoComplete}
                disabled={disabled}
                onChange={(event) => onValueChange(event.target.value)}
            />
            {busy ? (
                <>
                    <span className="app-search-box__spinner" aria-hidden="true" />
                    <span className="sr-only" role="status" aria-live="polite">{busyText}</span>
                </>
            ) : value.trim() !== '' && showClearButton ? (
                <button
                    type="button"
                    className="app-search-box__clear"
                    onClick={() => onValueChange('')}
                    aria-label={clearAriaLabel}
                >×</button>
            ) : null}
        </div>
    );
}
