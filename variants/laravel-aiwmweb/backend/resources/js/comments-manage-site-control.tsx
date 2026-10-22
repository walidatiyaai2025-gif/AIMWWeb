import React from 'react';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const COMMENTS_MANAGE_SITE_OPERATION_ID = 'AIMW-COMM-C083D47BC4';

type ActiveSite = {
    id: number;
    name?: string;
    status?: string;
};

type SiteAwareFrontendContext = FrontendContext & {
    active_site?: ActiveSite | null;
};

const commentsRoute = workspaceRoutes.find((route) => route.key === 'comments');

export function authoritativeCommentsManageHref(context: FrontendContext): string | null {
    const siteAware = context as SiteAwareFrontendContext;
    const siteId = siteAware.active_site?.id;

    if (!commentsRoute || !context.permissions.includes('tenant.view')) return null;
    if (resolveCapability(context, commentsRoute).state !== 'enabled') return null;
    if (!Number.isSafeInteger(siteId) || Number(siteId) <= 0) return null;

    const endpoint = context.api.comments;
    if (!endpoint) return null;

    try {
        const resolved = new URL(endpoint, window.location.origin);
        const expectedPath = `/api/v1/tenants/${encodeURIComponent(context.tenant.slug)}/sites/${siteId}/comments`;
        if (resolved.origin !== window.location.origin) return null;
        if (resolved.pathname !== expectedPath || resolved.search || resolved.hash) return null;
    } catch {
        return null;
    }

    return tenantUrl(context.tenant.slug, `/sites/${siteId}/comments`);
}

export function CommentsManageSiteControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const href = authoritativeCommentsManageHref(context);

    if (!href) return null;

    return (
        <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'إدارة تعليقات الموقع المحدد' : 'Manage selected site comments'}>
            <div className="toolbar-actions">
                <a
                    className="btn compact primary"
                    href={href}
                    data-canonical-operation={COMMENTS_MANAGE_SITE_OPERATION_ID}
                >
                    {locale === 'ar' ? 'إدارة' : 'Manage'}
                </a>
            </div>
        </nav>
    );
}
