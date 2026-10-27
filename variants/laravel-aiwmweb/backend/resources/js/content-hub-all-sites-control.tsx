import React from 'react';
import { Link, useLocation } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CONTENT_HUB_ALL_SITES_OPERATION_ID = 'AIMW-CONT-4D6C928D75';

export function ContentHubAllSitesControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const location = useLocation();
    const site = new URLSearchParams(location.search).get('site');
    const hasValidatedSiteContext = Boolean(site && /^\d+$/.test(site) && Number(site) > 0);
    const canViewContent = context.permissions.includes('*') || context.permissions.includes('content.view');

    if (!canViewContent || !hasValidatedSiteContext) return null;

    return (
        <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'تنقل مركز المحتوى' : 'Content hub navigation'}>
            <div className="toolbar-actions">
                <Link
                    className="btn"
                    to={tenantUrl(context.tenant.slug, '/content')}
                    data-canonical-operation={CONTENT_HUB_ALL_SITES_OPERATION_ID}
                >
                    {locale === 'ar' ? 'كل المواقع' : 'All sites'}
                </Link>
            </div>
        </nav>
    );
}
