import React from 'react';
import { useLocale } from './i18n';

export const CONTENT_EXPLORER_MEDIA_SOURCE_URL_OPERATION_ID = 'AIMW-CONT-B44DA81C28';
export const GLOBAL_MEDIA_SOURCE_URL_OPERATION_ID = 'AIMW-MEDI-3444B17C11';

export function safeContentExplorerMediaSourceUrl(value: unknown): string | null {
    if (typeof value !== 'string' || value.trim() !== value || value.length === 0) return null;

    try {
        const parsed = new URL(value);
        if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return null;
        if (parsed.username || parsed.password) return null;
        return value;
    } catch {
        return null;
    }
}

export function ContentExplorerMediaSourceUrlControl({ value }: { value: unknown }) {
    const { locale } = useLocale();
    const safeUrl = safeContentExplorerMediaSourceUrl(value);
    if (!safeUrl) return null;

    return (
        <a
            className="btn compact"
            href={safeUrl}
            target="_blank"
            rel="noopener noreferrer"
            data-canonical-operation={CONTENT_EXPLORER_MEDIA_SOURCE_URL_OPERATION_ID}
            data-canonical-global-media-operation={GLOBAL_MEDIA_SOURCE_URL_OPERATION_ID}
            aria-label={locale === 'ar' ? 'فتح ملف الوسائط' : 'Open media file'}
        >
            {locale === 'ar' ? 'فتح الملف' : 'Open file'} ↗
        </a>
    );
}
