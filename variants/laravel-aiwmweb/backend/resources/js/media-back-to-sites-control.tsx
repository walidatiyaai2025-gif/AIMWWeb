import React from 'react';
import { Link } from 'react-router-dom';
import { ApiError, tenantUrl } from './core';
import { useLocale } from './i18n';

export const MEDIA_BACK_TO_SITES_OPERATION_ID = 'AIMW-MEDI-34B5C789D2';

export function isAuthoritativeMediaNotFound(error: unknown): boolean {
    return error instanceof ApiError && error.status === 404;
}

export function MediaBackToSitesControl({
    tenantSlug,
    error,
}: {
    tenantSlug: string;
    error: unknown;
}) {
    const { locale } = useLocale();

    if (!isAuthoritativeMediaNotFound(error)) return null;
    if (!tenantSlug.trim() || /[\\/]/.test(tenantSlug)) return null;

    const target = tenantUrl(tenantSlug, '/sites');

    return (
        <nav
            className="toolbar-panel media-navigation"
            aria-label={locale === 'ar' ? 'تنقل الوسائط' : 'Media navigation'}
            data-canonical-operation={MEDIA_BACK_TO_SITES_OPERATION_ID}
        >
            <Link
                className="btn"
                to={target}
                data-testid="media-back-to-sites"
            >
                ← {locale === 'ar' ? 'العودة إلى المواقع' : 'Back to sites'}
            </Link>
        </nav>
    );
}
