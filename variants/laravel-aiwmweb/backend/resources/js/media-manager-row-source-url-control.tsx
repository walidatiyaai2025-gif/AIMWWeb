import React, { type ReactNode } from 'react';
import { safeContentExplorerMediaSourceUrl } from './content-explorer-media-source-url-control';

export const MEDIA_MANAGER_ROW_SOURCE_URL_OPERATION_ID = 'AIMW-MEDI-34E7DCA832';

// Preserve one visible Open file action while recording the MediaManager row operation separately.
// The shared SourceUrl control remains authoritative for URL validation and external-link hardening.
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
            data-testid="media-manager-row-source-url"
            data-canonical-operation={MEDIA_MANAGER_ROW_SOURCE_URL_OPERATION_ID}
        >
            {children}
        </span>
    );
}
