export const APP_DATA_GRID_PREVIOUS_PAGE_OPERATION_ID = 'AIMW-SYNC-598142307A';

export const APP_DATA_GRID_PREVIOUS_PAGE_SOURCE_OPERATION_KEY =
    'visible:src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor:component:AppDataGrid:@PreviousPageText [PreviousPageAsync]';

export function previousAppDataGridPage(currentPage: number): number {
    return currentPage > 1 ? currentPage - 1 : currentPage;
}
