import React from 'react';
import { Link } from 'react-router-dom';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const GLOBAL_TAXONOMY_POSTS_OPERATION_ID = 'AIMW-TAXO-EC57E21E70';

const postsRoute = workspaceRoutes.find((route) => route.key === 'posts');

export const canOpenGlobalTaxonomyPosts = (context: FrontendContext): boolean =>
    context.permissions.includes('tenant.view')
    && Boolean(postsRoute)
    && resolveCapability(context, postsRoute!).state === 'enabled';

export function GlobalTaxonomyPostsLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    if (!postsRoute || !canOpenGlobalTaxonomyPosts(context)) return null;

    return (
        <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'إجراءات التصنيفات والوسوم' : 'Taxonomy actions'}>
            <div className="toolbar-actions">
                <Link
                    className="btn"
                    to={tenantUrl(context.tenant.slug, postsRoute.path)}
                    data-canonical-operation={GLOBAL_TAXONOMY_POSTS_OPERATION_ID}
                >
                    {locale === 'ar' ? 'المقالات' : 'Posts'}
                </Link>
            </div>
        </nav>
    );
}