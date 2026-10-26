import React from 'react';
import { safeContentExplorerMediaSourceUrl } from './content-explorer-media-source-url-control';
import { useLocale } from './i18n';

export const MEDIA_MANAGER_ROW_SOURCE_URL_OPERATION_ID = 'AIMW-MEDI-34E7DCA832';

export function MediaManagerRowSourceUrlControl({ value }: { value: unknown }) {
    const { locale } = useLocale();
    const safeUrl = safeContentExplorerMediaSourceUrl(value);

    if (!safeUrl) return null;

    return (
        <a
            className="btn compact"
            href={safeUrl}
            target="_blank"
            rel="noopener noreferrer"
            data-canonical-operation={MEDIA_MANAGER_ROW_SOURCE_URL_OPERATION_ID}
            data-source-control="media-manager-row-source-url"
            aria-label={locale === 'ar' ? 'فتح ملف الوسائط' : 'Open media file'}
        >
            {locale === 'ar' ? 'فتح الملف' : 'Open file'} ↗
        </a>
    );
}
