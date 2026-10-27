import React from 'react';
import { Link } from 'react-router-dom';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const PAGES_POSTS_OPERATION_ID = 'AIMW-CONT-058F41BD1B';

const postsRoute = workspaceRoutes.find((route) => route.key === 'posts');

const canOpenPosts = (context: FrontendContext): boolean =>
    context.permissions.includes('tenant.view')
    && Boolean(postsRoute)
    && resolveCapability(context, postsRoute!).state === 'enabled';

export function PagesPostsLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    if (!postsRoute || !canOpenPosts(context)) return null;

    return (
        <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'إجراءات الصفحات' : 'Pages actions'}>
            <div className="toolbar-actions">
                <Link
                    className="btn"
                    to={tenantUrl(context.tenant.slug, postsRoute.path)}
                    data-canonical-operation={PAGES_POSTS_OPERATION_ID}
                >
                    {locale === 'ar' ? 'المقالات' : 'Posts'}
                </Link>
            </div>
        </nav>
    );
}
