import React from 'react';
import { Link } from 'react-router-dom';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const GLOBAL_POSTS_OPEN_SYNCHRONIZATION_OPERATION_ID = 'AIMW-SYNC-EEFDDFFF87';

const postsRoute = workspaceRoutes.find((route) => route.key === 'posts');
const syncRoute = workspaceRoutes.find((route) => route.key === 'sync');

export function globalPostsSynchronizationDestination(context: FrontendContext): string | null {
    const has = (name: string) => context.permissions.includes('*') || context.permissions.includes(name);
    if (!has('tenant.view') || !has('content.view') || !has('sync.view')) return null;
    if (!context.tenant.slug.trim() || !postsRoute || !syncRoute) return null;
    if (resolveCapability(context, postsRoute).state !== 'enabled') return null;
    if (resolveCapability(context, syncRoute).state !== 'enabled') return null;
    return tenantUrl(context.tenant.slug, syncRoute.path);
}

export function GlobalPostsOpenSynchronizationLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const destination = globalPostsSynchronizationDestination(context);
    if (!destination) return null;

    return (
        <nav
            className="toolbar-panel"
            aria-label={locale === 'ar' ? 'تنقل المقالات إلى المزامنة' : 'Posts to synchronization navigation'}
            data-canonical-operation={GLOBAL_POSTS_OPEN_SYNCHRONIZATION_OPERATION_ID}
        >
            <div className="toolbar-actions">
                <Link className="btn primary" to={destination}>
                    {locale === 'ar' ? 'فتح المزامنة' : 'Open synchronization'}
                </Link>
            </div>
        </nav>
    );
}
