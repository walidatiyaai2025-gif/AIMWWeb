import React from 'react';
import { useLocale } from './i18n';

export const COMMENTS_COMMENT_LINK_OPERATION_ID = 'AIMW-COMM-B16FBF4792';

export function safeExternalCommentUrl(value: unknown): string | null {
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

export function CommentsCommentLinkControl({ value }: { value: unknown }) {
    const { locale } = useLocale();
    const safeUrl = safeExternalCommentUrl(value);

    return (
        <span data-canonical-operation={COMMENTS_COMMENT_LINK_OPERATION_ID}>
            {safeUrl ? (
                <a href={safeUrl} target="_blank" rel="noopener noreferrer">
                    {locale === 'ar' ? 'فتح' : 'Open'} ↗
                </a>
            ) : (
                <span aria-label={locale === 'ar' ? 'رابط التعليق غير متاح' : 'Comment link unavailable'}>—</span>
            )}
        </span>
    );
}
