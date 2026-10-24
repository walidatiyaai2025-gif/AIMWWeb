import React from 'react';

export const SITES_SITE_URL_LINK_OPERATION_ID = 'AIMW-CONT-44A3B8E551';

export function safeExternalSiteUrl(value: unknown): string | null {
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

export function SitesSiteUrlLinkControl({ value }: { value: unknown }) {
    const safeUrl = safeExternalSiteUrl(value);
    if (!safeUrl) return null;

    return (
        <a
            className="site-url-link"
            href={safeUrl}
            target="_blank"
            rel="noopener noreferrer"
            data-canonical-operation={SITES_SITE_URL_LINK_OPERATION_ID}
        >
            {safeUrl}
        </a>
    );
}
