import React, { type ReactNode } from 'react';
import { safeContentExplorerMediaSourceUrl } from './content-explorer-media-source-url-control';

export const MEDIA_MANAGER_ROW_SOURCE_URL_OPERATION_ID = 'AIMW-MEDI-34E7DCA832';

// Preserve the existing hardened SourceUrl anchor and sibling operation markers.
// This wrapper records the exact MediaManager row-level canonical operation without
// rendering a duplicate action or introducing a second data source.
export function MediaManagerRowSourceUrlControl({
    value,
    children,
}: {
    value: unknown;
    children: ReactNode;
}) {
    if (!safeContentExplorerMediaSourceUrl(value)) return null;

    return (
        <span
            className="media-manager-row-source-url"
            data-source-control="media-manager-row-source-url"
            data-canonical-operation={MEDIA_MANAGER_ROW_SOURCE_URL_OPERATION_ID}
        >
            {children}
        </span>
    );
}
