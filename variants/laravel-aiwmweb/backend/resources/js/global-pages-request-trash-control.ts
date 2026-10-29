// RequestTrashAsync is a confirmation-only action. WordPress is mutated exclusively
// by the separately authorized ConfirmTrashAsync action after explicit confirmation.
export const GLOBAL_PAGES_REQUEST_TRASH_OPERATION_ID = 'AIMW-SYNC-E3EB27C678';

export function canRequestGlobalPagesTrash(selectedCount: number, canEdit: boolean, busy: boolean): boolean {
    return Number.isSafeInteger(selectedCount) && selectedCount >= 1 && selectedCount <= 100 && canEdit && !busy;
}
