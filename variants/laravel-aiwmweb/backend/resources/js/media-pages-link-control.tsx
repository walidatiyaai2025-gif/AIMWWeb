import React from 'react';
import { Link } from 'react-router-dom';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const MEDIA_PAGES_OPERATION_ID = 'AIMW-MEDI-0694CEEBBA';
export const MEDIA_POSTS_OPERATION_ID = 'AIMW-MEDI-F55A549341';
export const MEDIA_GLOBAL_OPERATION_ID = 'AIMW-MEDI-56F4EF0AFB';
export const MEDIA_BACK_TO_EXPLORER_OPERATION_ID = 'AIMW-MEDI-8F93BEDD59';

const mediaRoute = workspaceRoutes.find((route) => route.key === 'media');
const pagesRoute = workspaceRoutes.find((route) => route.key === 'pages');
const postsRoute = workspaceRoutes.find((route) => route.key === 'posts');
const explorerRoute = workspaceRoutes.find((route) => route.key === 'explorer');

type SiteAwareFrontendContext = FrontendContext & {
    active_site?: { id: number; name?: string; status?: string } | null;
};

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

export function authoritativeMediaExplorerHref(context: FrontendContext): string | null {
    const siteAware = context as SiteAwareFrontendContext;
    const siteId = siteAware.active_site?.id;

    if (!mediaRoute || !explorerRoute || !context.permissions.includes('tenant.view')) return null;
    if (resolveCapability(context, mediaRoute).state !== 'enabled') return null;

    const hasExplorerPermission = !explorerRoute.permission
        || context.permissions.includes(explorerRoute.permission)
        || context.permissions.includes('*');
    if (!hasExplorerPermission) return null;

    const explicitExplorer = context.capabilities['explorer.view'] ?? context.capabilities.explorer;
    if (explicitExplorer && explicitExplorer.state !== 'enabled') return null;
    if (!Number.isSafeInteger(siteId) || Number(siteId) <= 0) return null;

    const endpoint = context.api.media;
    if (!endpoint) return null;

    try {
        const resolved = new URL(endpoint, window.location.origin);
        const expectedPath = `/api/v1/tenants/${encodeURIComponent(context.tenant.slug)}/sites/${siteId}/media`;
        if (resolved.origin !== window.location.origin) return null;
        if (resolved.pathname !== expectedPath || resolved.search || resolved.hash) return null;
    } catch {
        return null;
    }

    return `${tenantUrl(context.tenant.slug, '/explorer')}?site=${encodeURIComponent(String(siteId))}`;
}

export function MediaPagesLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canOpenPosts = Boolean(postsRoute) && canOpenPostsFromMedia(context);
    const canOpenPages = Boolean(pagesRoute) && canOpenPagesFromMedia(context);
    const canOpenGlobal = Boolean(mediaRoute) && canOpenGlobalMedia(context);
    const explorerHref = authoritativeMediaExplorerHref(context);

    if (!canOpenPosts && !canOpenPages && !canOpenGlobal && !explorerHref) return null;

    return (
        <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'إجراءات مستكشف الوسائط' : 'Media Explorer actions'}>
            <div className="toolbar-actions">
                {explorerHref ? (
                    <Link
                        className="btn"
                        to={explorerHref}
                        data-canonical-operation={MEDIA_BACK_TO_EXPLORER_OPERATION_ID}
                    >
                        {locale === 'ar' ? 'العودة إلى المستكشف' : 'Back to Explorer'}
                    </Link>
                ) : null}
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
