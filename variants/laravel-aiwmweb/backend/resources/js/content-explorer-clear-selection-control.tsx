import React from 'react';
import { useLocale } from './i18n';

export const CONTENT_EXPLORER_CLEAR_SELECTION_OPERATION_ID = 'AIMW-CONT-F9015FDB11';

export function ContentExplorerClearSelectionControl({
    canEdit,
    selectedCount,
    busy = false,
    onClear,
}: {
    canEdit: boolean;
    selectedCount: number;
    busy?: boolean;
    onClear: () => void;
}) {
    const { locale } = useLocale();

    if (!canEdit || selectedCount <= 0) return null;

    const label = locale === 'ar' ? 'مسح التحديد' : 'Clear selection';

    return (
        <button
            type="button"
            className="icon-button"
            data-canonical-operation={CONTENT_EXPLORER_CLEAR_SELECTION_OPERATION_ID}
            aria-label={label}
            title={label}
            disabled={busy}
            onClick={onClear}
        >
            <span aria-hidden="true">×</span>
        </button>
    );
}
