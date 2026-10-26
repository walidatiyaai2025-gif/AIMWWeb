import React, { type ReactNode } from 'react';
import { safeContentExplorerMediaSourceUrl } from './content-explorer-media-source-url-control';

export const MEDIA_MANAGER_EDITING_SOURCE_URL_OPERATION_ID = 'AIMW-MEDI-03A0D722AD';

// Keep one visible SourceUrl action while recording the source editing-pane operation separately.
export function MediaManagerEditingSourceUrlControl({
    value,
    children,
}: {
    value: unknown;
    children: ReactNode;
}) {
    if (!safeContentExplorerMediaSourceUrl(value)) return null;

    return (
        <span
            className="media-manager-editing-source-url"
            data-testid="media-manager-editing-source-url"
            data-canonical-operation={MEDIA_MANAGER_EDITING_SOURCE_URL_OPERATION_ID}
        >
            {children}
        </span>
    );
}
