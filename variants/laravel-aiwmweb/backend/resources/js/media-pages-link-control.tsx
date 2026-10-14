import React from 'react';
import { Link } from 'react-router-dom';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const MEDIA_PAGES_OPERATION_ID = 'AIMW-MEDI-0694CEEBBA';

const mediaRoute = workspaceRoutes.find((route) => route.key === 'media');
const pagesRoute = workspaceRoutes.find((route) => route.key === 'pages');

const canOpenPagesFromMedia = (context: FrontendContext): boolean =>
    context.permissions.includes('tenant.view')
    && Boolean(mediaRoute)
    && Boolean(pagesRoute)
    && resolveCapability(context, mediaRoute!).state === 'enabled'
    && resolveCapability(context, pagesRoute!).state === 'enabled';

export function MediaPagesLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    if (!pagesRoute || !canOpenPagesFromMedia(context)) return null;

    return (
        <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'إجراءات مستكشف الوسائط' : 'Media Explorer actions'}>
            <div className="toolbar-actions">
                <Link
                    className="btn"
                    to={tenantUrl(context.tenant.slug, pagesRoute.path)}
                    data-canonical-operation={MEDIA_PAGES_OPERATION_ID}
                >
                    {locale === 'ar' ? 'الصفحات' : 'Pages'}
                </Link>
            </div>
        </nav>
    );
}
