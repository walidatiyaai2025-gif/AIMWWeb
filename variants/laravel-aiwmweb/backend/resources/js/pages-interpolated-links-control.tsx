import React from 'react';
import { tenantUrl } from './core';
import { useLocale } from './i18n';
import { normalizeAuthoritativeSiteId } from './sites-interpolated-links-control';

export const PAGES_INTERPOLATED_LINKS_OPERATION_ID = 'AIMW-CONT-BB31987A53';

export function pagesInterpolatedDestinations(
    tenantSlug: string,
    siteId: unknown,
    wordpressId: unknown,
): { site: string; editor: string; explorer: string } | null {
    const normalizedSiteId = normalizeAuthoritativeSiteId(siteId);
    const normalizedWordPressId = normalizeAuthoritativeSiteId(wordpressId);
    if (!tenantSlug || !normalizedSiteId || !normalizedWordPressId) return null;

    return {
        site: tenantUrl(tenantSlug, `/sites/${normalizedSiteId}`),
        editor: tenantUrl(
            tenantSlug,
            `/sites/${normalizedSiteId}/content/page/${normalizedWordPressId}/edit`,
        ),
        explorer: tenantUrl(
            tenantSlug,
            `/explorer?site=${encodeURIComponent(normalizedSiteId)}`,
        ),
    };
}

export function PagesInterpolatedLinksControl({
    tenantSlug,
    siteId,
    wordpressId,
    siteName,
}: {
    tenantSlug: string;
    siteId: unknown;
    wordpressId: unknown;
    siteName?: unknown;
}) {
    const { locale } = useLocale();
    const destinations = pagesInterpolatedDestinations(tenantSlug, siteId, wordpressId);
    if (!destinations) return null;

    const authoritativeSiteName = typeof siteName === 'string' && siteName.trim()
        ? siteName.trim()
        : (locale === 'ar' ? 'الموقع' : 'Site');

    return (
        <span
            className="site-row-actions"
            data-canonical-operation={PAGES_INTERPOLATED_LINKS_OPERATION_ID}
        >
            <a
                href={destinations.site}
                data-source-control="site-details"
                data-canonical-operation={PAGES_INTERPOLATED_LINKS_OPERATION_ID}
            >
                {authoritativeSiteName}
            </a>
            <span aria-hidden="true"> · </span>
            <a
                className="btn compact primary"
                href={destinations.editor}
                data-source-control="page-editor"
                data-canonical-operation={PAGES_INTERPOLATED_LINKS_OPERATION_ID}
            >
                {locale === 'ar' ? 'تعديل' : 'Edit'}
            </a>
            <span aria-hidden="true"> · </span>
            <a
                className="btn compact"
                href={destinations.explorer}
                data-source-control="site-explorer"
                data-canonical-operation={PAGES_INTERPOLATED_LINKS_OPERATION_ID}
            >
                {locale === 'ar' ? 'المستكشف' : 'Explorer'}
            </a>
        </span>
    );
}
