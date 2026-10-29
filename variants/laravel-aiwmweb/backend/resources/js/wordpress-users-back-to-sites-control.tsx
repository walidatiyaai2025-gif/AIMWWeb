import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const WORDPRESS_USERS_BACK_TO_SITES_OPERATION_ID = 'AIMW-CONT-B1024628D9';

export function WordPressUsersBackToSitesControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const target = tenantUrl(context.tenant.slug, '/sites');

    return (
        <nav
            className="toolbar-panel wordpress-users-navigation"
            aria-label={locale === 'ar' ? 'تنقل مستخدمي ووردبريس' : 'WordPress users navigation'}
            data-canonical-operation={WORDPRESS_USERS_BACK_TO_SITES_OPERATION_ID}
        >
            <Link className="btn" to={target}>
                ← {locale === 'ar' ? 'العودة إلى المواقع' : 'Back to sites'}
            </Link>
        </nav>
    );
}
