import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SITE_DATA_SNAPSHOT_BACK_TO_SITES_OPERATION_ID = 'AIMW-CONT-CA4920CBC8';

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

export function authoritativeSiteDataSnapshotBackToSitesHref(context: FrontendContext): string | null {
    if (!hasPermission(context, 'tenant.view') || !hasPermission(context, 'sites.view')) return null;
    return tenantUrl(context.tenant.slug, '/sites');
}

export function SiteDataSnapshotBackToSitesControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const href = authoritativeSiteDataSnapshotBackToSitesHref(context);

    if (!href) return null;

    return (
        <nav
            className="toolbar-panel site-data-snapshot-missing-site-navigation"
            aria-label={locale === 'ar' ? 'العودة إلى المواقع' : 'Site snapshot recovery'}
            data-canonical-operation={SITE_DATA_SNAPSHOT_BACK_TO_SITES_OPERATION_ID}
        >
            <Link className="btn compact" to={href}>
                ← {locale === 'ar' ? 'العودة إلى المواقع' : 'Back to sites'}
            </Link>
        </nav>
    );
}
