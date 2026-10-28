// RequestTrashAsync opens the confirmation only. The separate ConfirmTrashAsync operation
// performs the authenticated live WordPress REST mutation after explicit user confirmation.
export const GLOBAL_POSTS_REQUEST_TRASH_OPERATION_ID = 'AIMW-SYNC-476CDB769B';

export function canRequestGlobalPostsTrash(selectedCount: number, canEdit: boolean, busy: boolean): boolean {
    return Number.isSafeInteger(selectedCount) && selectedCount >= 1 && selectedCount <= 100 && canEdit && !busy;
}
