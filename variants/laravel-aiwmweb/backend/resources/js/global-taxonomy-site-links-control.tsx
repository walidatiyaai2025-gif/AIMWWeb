import React from 'react';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const GLOBAL_TAXONOMY_SITE_LINKS_OPERATION_ID = 'AIMW-TAXO-602DE140EB';

export function normalizeGlobalTaxonomySiteId(value: unknown): string | null {
    if (typeof value === 'number') {
        return Number.isSafeInteger(value) && value > 0 ? String(value) : null;
    }

    if (typeof value !== 'string' || value.trim() !== value || !/^[1-9]\d*$/.test(value)) {
        return null;
    }

    return value;
}

export function globalTaxonomySiteDestinations(
    context: FrontendContext,
    siteId: unknown,
): { manage: string; explorer: string } | null {
    const normalizedSiteId = normalizeGlobalTaxonomySiteId(siteId);
    const hasPermission = context.permissions.includes('*')
        || (context.permissions.includes('tenant.view') && context.permissions.includes('content.view'));

    if (!hasPermission || !normalizedSiteId || !context.api.taxonomy) return null;

    return {
        manage: `${tenantUrl(context.tenant.slug, '/module/taxonomy')}?site=${encodeURIComponent(normalizedSiteId)}`,
        explorer: `${tenantUrl(context.tenant.slug, '/explorer')}?site=${encodeURIComponent(normalizedSiteId)}`,
    };
}

export function GlobalTaxonomySiteLinksControl({
    context,
    siteId,
}: {
    context: FrontendContext;
    siteId: unknown;
}) {
    const { locale } = useLocale();
    const destinations = globalTaxonomySiteDestinations(context, siteId);

    if (!destinations) return null;

    return (
        <span
            className="site-row-actions"
            data-canonical-operation={GLOBAL_TAXONOMY_SITE_LINKS_OPERATION_ID}
        >
            <a className="btn compact primary" href={destinations.manage}>
                {locale === 'ar' ? 'إدارة' : 'Manage'}
            </a>
            <span aria-hidden="true"> · </span>
            <a className="btn compact" href={destinations.explorer}>
                {locale === 'ar' ? 'المستكشف' : 'Explorer'}
            </a>
        </span>
    );
}
