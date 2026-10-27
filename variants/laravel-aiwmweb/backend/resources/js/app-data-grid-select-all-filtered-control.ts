export const APP_DATA_GRID_SELECT_ALL_FILTERED_OPERATION_ID = 'AIMW-SYNC-3BFC8AF183';

export const APP_DATA_GRID_SELECT_ALL_FILTERED_SOURCE_OPERATION_KEY =
    'visible:src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor:component:AppDataGrid:@SelectAllFilteredText [SelectAllFilteredAsync]';

export function selectAllAppDataGridFiltered<TKey>(
    current: ReadonlySet<TKey>,
    filteredKeys: readonly TKey[],
): Set<TKey> {
    const next = new Set(current);
    for (const key of filteredKeys) next.add(key);
    return next;
}

export function areAllAppDataGridFilteredSelected<TKey>(
    selected: ReadonlySet<TKey>,
    filteredKeys: readonly TKey[],
): boolean {
    return filteredKeys.length > 0 && filteredKeys.every((key) => selected.has(key));
}

export function canSelectAllAppDataGridFiltered(
    filteredCount: number,
    pagedCount: number,
): boolean {
    return filteredCount > pagedCount;
}
