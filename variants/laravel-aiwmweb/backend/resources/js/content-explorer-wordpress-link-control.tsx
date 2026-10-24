import React from 'react';
import { useLocale } from './i18n';

export const CONTENT_EXPLORER_WORDPRESS_LINK_OPERATION_ID = 'AIMW-CONT-16D4AD8DC8';

export function safeContentExplorerExternalUrl(value: unknown): string | null {
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

export function ContentExplorerWordPressLinkControl({ value }: { value: unknown }) {
    const { locale } = useLocale();
    const safeUrl = safeContentExplorerExternalUrl(value);
    if (!safeUrl) return null;

    return (
        <a
            className="btn compact"
            href={safeUrl}
            target="_blank"
            rel="noopener noreferrer"
            data-canonical-operation={CONTENT_EXPLORER_WORDPRESS_LINK_OPERATION_ID}
            aria-label={locale === 'ar' ? 'فتح المحتوى في WordPress' : 'Open content in WordPress'}
        >
            ↗
        </a>
    );
}
