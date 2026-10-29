export const APP_DATA_GRID_CSV_EXPORT_OPERATION_ID = 'AIMW-SYNC-EB9202408A';

export const APP_DATA_GRID_CSV_EXPORT_SOURCE_OPERATION_KEY =
    'visible:src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor:component:AppDataGrid:@ExportText [ExportCsvAsync]';

export type AppDataGridCsvRow = readonly string[];

export async function requestAppDataGridCsvExport<T>(
    rows: readonly T[],
    rowSelector: (row: T) => AppDataGridCsvRow,
    download: (rows: readonly AppDataGridCsvRow[]) => void | Promise<void>,
): Promise<boolean> {
    if (rows.length === 0) return false;
    const selectedRows = rows.map((row) => [...rowSelector(row)]);
    await download(selectedRows);
    return true;
}
