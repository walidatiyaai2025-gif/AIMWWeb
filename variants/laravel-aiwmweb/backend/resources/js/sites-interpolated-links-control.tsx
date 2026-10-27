import React from 'react';
import { tenantUrl } from './core';
import { useLocale } from './i18n';

export const SITES_INTERPOLATED_LINKS_OPERATION_ID = 'AIMW-CONT-3A4E69E5D8';
export const SITES_OPEN_OPERATION_ID = 'AIMW-CONT-EE659E3F13';

export function normalizeAuthoritativeSiteId(value: unknown): string | null {
    if (typeof value === 'number') {
        return Number.isSafeInteger(value) && value > 0 ? String(value) : null;
    }

    if (typeof value !== 'string' || value.trim() !== value || !/^[1-9]\d*$/.test(value)) {
        return null;
    }

    return value;
}

export function sitesInterpolatedDestinations(tenantSlug: string, siteId: unknown): { dashboard: string; explorer: string } | null {
    const normalizedSiteId = normalizeAuthoritativeSiteId(siteId);
    if (!tenantSlug || !normalizedSiteId) return null;

    return {
        dashboard: tenantUrl(tenantSlug, `/sites/${normalizedSiteId}`),
        explorer: tenantUrl(tenantSlug, `/explorer?site=${encodeURIComponent(normalizedSiteId)}`),
    };
}

export function SitesInterpolatedLinksControl({
    tenantSlug,
    siteId,
}: {
    tenantSlug: string;
    siteId: unknown;
}) {
    const { locale } = useLocale();
    const destinations = sitesInterpolatedDestinations(tenantSlug, siteId);
    if (!destinations) return null;

    return (
        <span className="site-internal-links" data-canonical-operation={SITES_INTERPOLATED_LINKS_OPERATION_ID}>
            <a href={destinations.dashboard} data-canonical-operation={SITES_OPEN_OPERATION_ID}>
                {locale === 'ar' ? 'فتح' : 'Open'}
            </a>
            <span aria-hidden="true"> · </span>
            <a href={destinations.explorer}>
                {locale === 'ar' ? 'المحتوى' : 'Content'}
            </a>
        </span>
    );
}
