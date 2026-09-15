import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SITE_DETAILS_SITE_URL_OPERATION_ID = 'AIMW-AI-A8D10964C6';

type SiteUrlPayload = {
    id?: number;
    name?: string;
    url?: unknown;
};

export function safeExternalSiteUrl(value: unknown): string | null {
    if (typeof value !== 'string' || value.trim() !== value || value.length === 0) return null;

    try {
        const parsed = new URL(value);
        if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return null;
        return value;
    } catch {
        return null;
    }
}

export function SiteDetailsSiteUrlControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const { siteId } = useParams();
    const endpoint = siteId ? context.api[`sites.detail.${siteId}`] : undefined;
    const query = useQuery({
        queryKey: ['canonical-site-details-site-url', context.tenant.slug, siteId, endpoint],
        queryFn: () => apiRequest<SiteUrlPayload>(endpoint!),
        enabled: Boolean(endpoint),
    });

    if (!endpoint || query.isLoading || query.error) return null;

    const siteUrl = safeExternalSiteUrl(query.data?.url);
    if (!siteUrl) return null;

    return (
        <section
            className="toolbar-panel site-details-site-url"
            aria-label={locale === 'ar' ? 'رابط موقع WordPress' : 'WordPress site URL'}
            data-canonical-operation={SITE_DETAILS_SITE_URL_OPERATION_ID}
        >
            <span className="workspace-kicker">{locale === 'ar' ? 'الموقع المباشر' : 'LIVE SITE'}</span>
            <a href={siteUrl} target="_blank" rel="noopener noreferrer">
                {siteUrl} ↗
            </a>
        </section>
    );
}
