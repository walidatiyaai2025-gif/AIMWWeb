import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SITE_DATA_SNAPSHOT_BACK_TO_SITES_OPERATION_ID = 'AIMW-CONT-CA4920CBC8';

export function SiteDataSnapshotBackToSitesControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const target = tenantUrl(context.tenant.slug, '/sites');

    return (
        <nav
            className="toolbar-panel site-snapshot-navigation"
            aria-label={locale === 'ar' ? 'تنقل لقطة بيانات الموقع' : 'Site data snapshot navigation'}
            data-canonical-operation={SITE_DATA_SNAPSHOT_BACK_TO_SITES_OPERATION_ID}
        >
            <Link className="btn" to={target}>
                ← {locale === 'ar' ? 'العودة إلى المواقع' : 'Back to sites'}
            </Link>
        </nav>
    );
}
