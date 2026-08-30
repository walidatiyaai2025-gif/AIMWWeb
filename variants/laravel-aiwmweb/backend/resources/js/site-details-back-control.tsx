import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SITE_DETAILS_BACK_OPERATION_ID = 'AIMW-AI-1C0C5D3B7B';

export function SiteDetailsBackControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const target = tenantUrl(context.tenant.slug, '/sites');

    return (
        <nav
            className="toolbar-panel site-details-navigation"
            aria-label={locale === 'ar' ? 'تنقل تفاصيل الموقع' : 'Site details navigation'}
            data-canonical-operation={SITE_DETAILS_BACK_OPERATION_ID}
        >
            <Link className="btn" to={target}>
                ← {locale === 'ar' ? 'العودة إلى المواقع' : 'Back to sites'}
            </Link>
        </nav>
    );
}
