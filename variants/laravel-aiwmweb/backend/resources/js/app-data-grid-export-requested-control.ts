export const APP_DATA_GRID_EXPORT_REQUESTED_OPERATION_ID = 'AIMW-SYNC-C18ED1F753';

export const APP_DATA_GRID_EXPORT_REQUESTED_SOURCE_OPERATION_KEY =
    'visible:src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor:component:AppDataGrid:@ExportText [ExportAsync]';

export type AppDataGridExportRow = Record<string, unknown>;

export async function requestAppDataGridExport<T extends AppDataGridExportRow>(
    rows: readonly T[],
    onExportRequested: (rows: readonly T[]) => void | Promise<void>,
): Promise<boolean> {
    if (rows.length === 0) return false;

    // AppDataGrid::ExportAsync forwards SortedItems.ToList() to the parent callback.
    // The Laravel consumer supplies rows in its current authoritative filtered order,
    // so clone the collection before invoking the consumer-owned export callback.
    await onExportRequested([...rows]);
    return true;
}
