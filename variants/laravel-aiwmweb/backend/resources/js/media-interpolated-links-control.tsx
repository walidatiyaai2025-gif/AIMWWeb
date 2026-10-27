import React from 'react';
import { tenantUrl } from './core';
import { useLocale } from './i18n';
import { normalizeAuthoritativeSiteId } from './sites-interpolated-links-control';

export const GLOBAL_MEDIA_INTERPOLATED_LINKS_OPERATION_ID = 'AIMW-MEDI-727FAEEB01';

export function globalMediaInterpolatedDestinations(
    tenantSlug: string,
    siteId: unknown,
): { manage: string; explorer: string } | null {
    const normalizedSiteId = normalizeAuthoritativeSiteId(siteId);
    if (!tenantSlug || !normalizedSiteId) return null;

    const encodedSiteId = encodeURIComponent(normalizedSiteId);
    return {
        manage: tenantUrl(tenantSlug, `/module/media?site=${encodedSiteId}`),
        explorer: tenantUrl(tenantSlug, `/explorer?site=${encodedSiteId}`),
    };
}

export function GlobalMediaInterpolatedLinksControl({
    tenantSlug,
    siteId,
}: {
    tenantSlug: string;
    siteId: unknown;
}) {
    const { locale } = useLocale();
    const destinations = globalMediaInterpolatedDestinations(tenantSlug, siteId);
    if (!destinations) return null;

    return (
        <span
            className="site-row-actions"
            data-canonical-operation={GLOBAL_MEDIA_INTERPOLATED_LINKS_OPERATION_ID}
        >
            <a
                className="btn compact primary"
                href={destinations.manage}
                data-source-control="manage-media"
                data-canonical-operation={GLOBAL_MEDIA_INTERPOLATED_LINKS_OPERATION_ID}
            >
                {locale === 'ar' ? 'إدارة وتحسين' : 'Manage & optimize'}
            </a>
            <span aria-hidden="true"> · </span>
            <a
                className="btn compact"
                href={destinations.explorer}
                data-source-control="site-explorer"
                data-canonical-operation={GLOBAL_MEDIA_INTERPOLATED_LINKS_OPERATION_ID}
            >
                {locale === 'ar' ? 'المستكشف' : 'Explorer'}
            </a>
        </span>
    );
}
