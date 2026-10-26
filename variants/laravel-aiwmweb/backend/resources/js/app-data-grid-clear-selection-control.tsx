import React, { useRef, useState } from 'react';
import type { Locale } from './core';

export const APP_DATA_GRID_CLEAR_SELECTION_OPERATION_ID = 'AIMW-SYNC-2E94028E4E';
export const APP_DATA_GRID_CLEAR_SELECTION_SOURCE_OPERATION_KEY =
    'visible:src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor:component:AppDataGrid:@ClearSelectionText [ClearSelectionAsync]';

export type AppDataGridClearSelectionCallbacks = {
    clearLocalSelection: () => void;
    onSelectionChanged?: (selectedKeys: readonly string[]) => void | Promise<void>;
};

export async function clearAppDataGridSelection({
    clearLocalSelection,
    onSelectionChanged,
}: AppDataGridClearSelectionCallbacks): Promise<void> {
    clearLocalSelection();
    await onSelectionChanged?.([]);
}

export function AppDataGridClearSelectionControl({
    locale,
    selectedCount,
    busy = false,
    onClearSelection,
    onSelectionChanged,
}: {
    locale: Locale;
    selectedCount: number;
    busy?: boolean;
    onClearSelection: () => void;
    onSelectionChanged?: (selectedKeys: readonly string[]) => void | Promise<void>;
}) {
    const inFlight = useRef(false);
    const [clearing, setClearing] = useState(false);

    if (selectedCount <= 0) return null;

    const clear = async (): Promise<void> => {
        if (busy || inFlight.current) return;

        inFlight.current = true;
        setClearing(true);
        try {
            await clearAppDataGridSelection({
                clearLocalSelection: onClearSelection,
                onSelectionChanged,
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
            data-canonical-operation={APP_DATA_GRID_CLEAR_SELECTION_OPERATION_ID}
            data-source-operation-key={APP_DATA_GRID_CLEAR_SELECTION_SOURCE_OPERATION_KEY}
            disabled={busy || clearing}
            aria-busy={busy || clearing ? 'true' : 'false'}
            onClick={() => void clear()}
        >
            {clearing
                ? (locale === 'ar' ? 'جارٍ مسح التحديد…' : 'Clearing selection…')
                : (locale === 'ar' ? 'مسح التحديد' : 'Clear selection')}
        </button>
    );
}
