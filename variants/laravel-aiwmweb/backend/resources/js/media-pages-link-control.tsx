import React from 'react';
import { Link } from 'react-router-dom';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const MEDIA_PAGES_OPERATION_ID = 'AIMW-MEDI-0694CEEBBA';
export const MEDIA_POSTS_OPERATION_ID = 'AIMW-MEDI-F55A549341';
export const MEDIA_GLOBAL_OPERATION_ID = 'AIMW-MEDI-56F4EF0AFB';

const mediaRoute = workspaceRoutes.find((route) => route.key === 'media');
const pagesRoute = workspaceRoutes.find((route) => route.key === 'pages');
const postsRoute = workspaceRoutes.find((route) => route.key === 'posts');

const canOpenPagesFromMedia = (context: FrontendContext): boolean =>
    context.permissions.includes('tenant.view')
    && Boolean(mediaRoute)
    && Boolean(pagesRoute)
    && resolveCapability(context, mediaRoute!).state === 'enabled'
    && resolveCapability(context, pagesRoute!).state === 'enabled';

const canOpenPostsFromMedia = (context: FrontendContext): boolean =>
    context.permissions.includes('tenant.view')
    && Boolean(mediaRoute)
    && Boolean(postsRoute)
    && resolveCapability(context, mediaRoute!).state === 'enabled'
    && resolveCapability(context, postsRoute!).state === 'enabled';

const canOpenGlobalMedia = (context: FrontendContext): boolean =>
    context.permissions.includes('tenant.view')
    && context.permissions.includes('content.view')
    && Boolean(mediaRoute)
    && Boolean(context.api.media)
    && resolveCapability(context, mediaRoute!).state === 'enabled';

export function MediaPagesLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canOpenPosts = Boolean(postsRoute) && canOpenPostsFromMedia(context);
    const canOpenPages = Boolean(pagesRoute) && canOpenPagesFromMedia(context);
    const canOpenGlobal = Boolean(mediaRoute) && canOpenGlobalMedia(context);

    if (!canOpenPosts && !canOpenPages && !canOpenGlobal) return null;

    return (
        <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'إجراءات مستكشف الوسائط' : 'Media Explorer actions'}>
            <div className="toolbar-actions">
                {canOpenGlobal && mediaRoute ? (
                    <Link
                        className="btn"
                        to={tenantUrl(context.tenant.slug, mediaRoute.path)}
                        data-canonical-operation={MEDIA_GLOBAL_OPERATION_ID}
                    >
                        {locale === 'ar' ? 'كل الوسائط' : 'Global media'}
                    </Link>
                ) : null}
                {canOpenPosts && postsRoute ? (
                    <Link
                        className="btn"
                        to={tenantUrl(context.tenant.slug, postsRoute.path)}
                        data-canonical-operation={MEDIA_POSTS_OPERATION_ID}
                    >
                        {locale === 'ar' ? 'المقالات' : 'Posts'}
                    </Link>
                ) : null}
                {canOpenPages && pagesRoute ? (
                    <Link
                        className="btn"
                        to={tenantUrl(context.tenant.slug, pagesRoute.path)}
                        data-canonical-operation={MEDIA_PAGES_OPERATION_ID}
                    >
                        {locale === 'ar' ? 'الصفحات' : 'Pages'}
                    </Link>
                ) : null}
            </div>
        </nav>
    );
}
