import React from 'react';
import { safeContentExplorerMediaSourceUrl } from './content-explorer-media-source-url-control';

export const GLOBAL_MEDIA_SOURCE_URL_OPERATION_ID = 'AIMW-MEDI-3444B17C11';

export function GlobalMediaSourceUrlOperationBinding({
    value,
    children,
}: {
    value: unknown;
    children: React.ReactNode;
}) {
    if (!safeContentExplorerMediaSourceUrl(value)) return null;

    return (
        <span data-canonical-operation={GLOBAL_MEDIA_SOURCE_URL_OPERATION_ID}>
            {children}
        </span>
    );
}
