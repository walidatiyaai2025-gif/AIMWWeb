import React from 'react';

export const APP_DATA_GRID_TOGGLE_SORT_OPERATION_ID = 'AIMW-SYNC-0108E40967';

export const APP_DATA_GRID_TOGGLE_SORT_SOURCE_OPERATION_KEY =
    'visible:src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor:component:AppDataGrid:@SortText [ToggleSortAsync]';

export function toggleAppDataGridSortDescending(sortDescending: boolean): boolean {
    return !sortDescending;
}

export function sortAppDataGridRows<T>(
    rows: readonly T[],
    keySelector: (row: T) => string | null | undefined,
    sortDescending: boolean,
): T[] {
    return [...rows].sort((left, right) => {
        const leftKey = keySelector(left) ?? '';
        const rightKey = keySelector(right) ?? '';
        const comparison = leftKey.localeCompare(rightKey, undefined, { sensitivity: 'base' });
        return sortDescending ? -comparison : comparison;
    });
}

export function AppDataGridSortControl({
    sortDescending,
    onToggle,
    locale = 'en',
}: {
    sortDescending: boolean;
    onToggle: (sortDescending: boolean) => void;
    locale?: 'en' | 'ar';
}) {
    const sortText = locale === 'ar' ? 'ترتيب' : 'Sort';
    const stateText = sortDescending
        ? (locale === 'ar' ? 'مرتب تنازليًا' : 'Sorted descending')
        : (locale === 'ar' ? 'مرتب تصاعديًا' : 'Sorted ascending');
    const accessibleState = `${sortText}: ${stateText}`;

    return (
        <button
            type="button"
            className="btn compact"
            data-canonical-operation={APP_DATA_GRID_TOGGLE_SORT_OPERATION_ID}
            aria-label={accessibleState}
            aria-pressed={sortDescending}
            title={accessibleState}
            onClick={() => onToggle(toggleAppDataGridSortDescending(sortDescending))}
        >
            <span aria-hidden="true">{sortDescending ? '↓' : '↑'}</span>
            <span>{sortText}</span>
        </button>
    );
}
