import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CONTENT_EXPLORER_BACK_TO_SITES_OPERATION_ID = 'AIMW-CONT-966F49CDAE';

export function ContentExplorerBackToSitesControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const target = tenantUrl(context.tenant.slug, '/sites');

    return (
        <nav
            className="toolbar-panel"
            aria-label={locale === 'ar' ? 'تنقل مستكشف المحتوى' : 'Content explorer navigation'}
            data-canonical-operation={CONTENT_EXPLORER_BACK_TO_SITES_OPERATION_ID}
        >
            <Link className="btn" to={target}>
                ← {locale === 'ar' ? 'العودة إلى المواقع' : 'Back to sites'}
            </Link>
        </nav>
    );
}
