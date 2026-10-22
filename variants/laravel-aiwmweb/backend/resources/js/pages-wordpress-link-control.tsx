import React from 'react';
import { useLocale } from './i18n';

export const PAGES_WORDPRESS_LINK_OPERATION_ID = 'AIMW-CONT-4209A8ADF4';

export function safeExternalContentUrl(value: unknown): string | null {
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

export function PagesWordPressLinkControl({ value }: { value: unknown }) {
    const { locale } = useLocale();
    const safeUrl = safeExternalContentUrl(value);
    if (!safeUrl) return null;

    return (
        <a
            className="btn compact"
            href={safeUrl}
            target="_blank"
            rel="noopener noreferrer"
            data-canonical-operation={PAGES_WORDPRESS_LINK_OPERATION_ID}
            aria-label={locale === 'ar' ? 'فتح الصفحة في WordPress' : 'Open page in WordPress'}
        >
            ↗
        </a>
    );
}
