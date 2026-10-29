import React, { useState } from 'react';

export const APP_FILTER_BAR_CLEAR_OPERATION_ID = 'AIMW-SYNC-F7CE702C7D';

export type AppFilterBarProps = {
    ariaLabel?: string;
    activeFiltersText?: string;
    noFiltersText?: string | null;
    appliedFiltersAriaLabel?: string;
    clearText?: string;
    clearAriaLabel?: string | null;
    className?: string;
    activeFilterCount?: number;
    busy?: boolean;
    children?: React.ReactNode;
    appliedFilters?: React.ReactNode;
    onClearRequested?: () => void | Promise<void>;
};

/**
 * Shared filter bar adapted from the canonical AppFilterBar Razor component.
 *
 * It owns presentation and callback gating only. It deliberately performs no
 * network request, persistence, tenant selection, or authoritative mutation.
 */
export function AppFilterBar({
    ariaLabel = 'Data filters',
    activeFiltersText = 'active filters',
    noFiltersText,
    appliedFiltersAriaLabel = 'Applied filters',
    clearText = 'Clear filters',
    clearAriaLabel,
    className = '',
    activeFilterCount = 0,
    busy = false,
    children,
    appliedFilters,
    onClearRequested,
}: AppFilterBarProps) {
    const [clearInFlight, setClearInFlight] = useState(false);
    const count = Math.max(0, Math.trunc(activeFilterCount));
    const effectiveBusy = busy || clearInFlight;
    const canClear = count > 0 && typeof onClearRequested === 'function';
    const classes = ['toolbar-panel', 'app-filter-bar', className].filter(Boolean).join(' ');

    const clearAsync = async (): Promise<void> => {
        if (effectiveBusy || !canClear || !onClearRequested) return;

        setClearInFlight(true);
        try {
            await onClearRequested();
        } finally {
            setClearInFlight(false);
        }
    };

    return (
        <section
            className={classes}
            role="search"
            aria-label={ariaLabel}
            aria-busy={effectiveBusy ? 'true' : 'false'}
        >
            <div className="app-filter-bar__controls">
                {children}
            </div>

            <div className="app-filter-bar__meta">
                {count > 0 ? (
                    <div className="app-filter-bar__active" role="status" aria-live="polite" aria-atomic="true">
                        <span className="count-badge" aria-label={`${count} ${activeFiltersText}`}>{count}</span>
                        <span>{activeFiltersText}</span>
                    </div>
                ) : noFiltersText ? (
                    <span className="app-filter-bar__none">{noFiltersText}</span>
                ) : null}

                {appliedFilters && count > 0 ? (
                    <div className="app-filter-bar__chips" aria-label={appliedFiltersAriaLabel}>
                        {appliedFilters}
                    </div>
                ) : null}

                {canClear ? (
                    <button
                        type="button"
                        className="btn"
                        data-canonical-operation={APP_FILTER_BAR_CLEAR_OPERATION_ID}
                        aria-label={clearAriaLabel ?? clearText}
                        aria-busy={effectiveBusy ? 'true' : 'false'}
                        disabled={effectiveBusy}
                        onClick={() => void clearAsync()}
                    >
                        <span aria-hidden="true">×</span>
                        <span>{clearText}</span>
                    </button>
                ) : null}
            </div>
        </section>
    );
}
