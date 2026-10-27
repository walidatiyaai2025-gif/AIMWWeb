import React, { useRef, useState } from 'react';
import type { Locale } from './core';

export const APP_DATA_GRID_CLEAR_FILTERS_OPERATION_ID = 'AIMW-SYNC-FF579F0EBF';
export const APP_DATA_GRID_CLEAR_FILTERS_SOURCE_OPERATION_KEY =
    'visible:src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor:component:AppDataGrid:@ClearFiltersText [ClearFiltersAsync]';

export type AppDataGridClearFiltersCallbacks = {
    onSearchInputChange: (value: string) => void;
    onPageChange: (page: number) => void;
    onSearchChanged: (value: string) => void | Promise<void>;
    onClearFiltersRequested: () => void | Promise<void>;
};

export async function clearAppDataGridFilters(callbacks: AppDataGridClearFiltersCallbacks): Promise<void> {
    callbacks.onSearchInputChange('');
    callbacks.onPageChange(1);
    await callbacks.onSearchChanged('');
    await callbacks.onClearFiltersRequested();
}

export function AppDataGridClearFiltersControl({
    locale,
    canonicalOperationAlias,
    searchValue,
    activeFilterCount,
    busy = false,
    onSearchInputChange,
    onPageChange,
    onSearchChanged,
    onClearFiltersRequested,
}: {
    locale: Locale;
    canonicalOperationAlias?: string;
    searchValue: string;
    activeFilterCount: number;
    busy?: boolean;
} & AppDataGridClearFiltersCallbacks) {
    const inFlight = useRef(false);
    const [clearing, setClearing] = useState(false);
    const effectiveActiveFilterCount = Math.max(0, activeFilterCount) + (searchValue.trim() ? 1 : 0);

    if (effectiveActiveFilterCount === 0) return null;

    const clear = async (): Promise<void> => {
        if (busy || inFlight.current) return;
        inFlight.current = true;
        setClearing(true);
        try {
            await clearAppDataGridFilters({
                onSearchInputChange,
                onPageChange,
                onSearchChanged,
                onClearFiltersRequested,
            });
        } finally {
            inFlight.current = false;
            setClearing(false);
        }
    };

    return (
        <button
            type="button"
            className="btn"
            data-canonical-operation={APP_DATA_GRID_CLEAR_FILTERS_OPERATION_ID}
            data-canonical-operation-alias={canonicalOperationAlias}
            data-source-operation-key={APP_DATA_GRID_CLEAR_FILTERS_SOURCE_OPERATION_KEY}
            disabled={busy || clearing}
            aria-busy={busy || clearing ? 'true' : 'false'}
            onClick={() => void clear()}
        >
            {clearing
                ? (locale === 'ar' ? 'جارٍ مسح الفلاتر…' : 'Clearing filters…')
                : (locale === 'ar' ? 'مسح الفلاتر' : 'Clear filters')}
        </button>
    );
}
