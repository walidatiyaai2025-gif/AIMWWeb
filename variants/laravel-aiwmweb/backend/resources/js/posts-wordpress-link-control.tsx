import React from 'react';
import { useLocale } from './i18n';

export const POSTS_WORDPRESS_LINK_OPERATION_ID = 'AIMW-CONT-DF8B12B6DE';

export function safeExternalPostUrl(value: unknown): string | null {
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

export function PostsWordPressLinkControl({ value }: { value: unknown }) {
    const { locale } = useLocale();
    const safeUrl = safeExternalPostUrl(value);
    if (!safeUrl) return null;

    return (
        <a
            className="btn compact"
            href={safeUrl}
            target="_blank"
            rel="noopener noreferrer"
            data-canonical-operation={POSTS_WORDPRESS_LINK_OPERATION_ID}
            aria-label={locale === 'ar' ? 'فتح المقال في WordPress' : 'Open post in WordPress'}
        >
            ↗
        </a>
    );
}
