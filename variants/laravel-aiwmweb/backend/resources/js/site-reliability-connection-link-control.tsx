import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl } from './core';
import { useLocale } from './i18n';
import { normalizeAuthoritativeSiteId } from './sites-interpolated-links-control';

export const SITE_RELIABILITY_CONNECTION_LINK_OPERATION_ID = 'AIMW-CONT-A93DE991C0';

export function siteReliabilityConnectionDestination(tenantSlug: string, siteId: unknown): string | null {
    const normalizedSiteId = normalizeAuthoritativeSiteId(siteId);
    if (!tenantSlug || !normalizedSiteId) return null;

    return tenantUrl(tenantSlug, `/sites/${normalizedSiteId}/connection`);
}

export function SiteReliabilityConnectionLinkControl({
    tenantSlug,
    siteId,
}: {
    tenantSlug: string;
    siteId: unknown;
}) {
    const { locale } = useLocale();
    const destination = siteReliabilityConnectionDestination(tenantSlug, siteId);
    if (!destination) return null;

    return (
        <Link
            className="btn"
            to={destination}
            data-canonical-operation={SITE_RELIABILITY_CONNECTION_LINK_OPERATION_ID}
        >
            {locale === 'ar' ? 'فتح' : 'Open'}
        </Link>
    );
}
