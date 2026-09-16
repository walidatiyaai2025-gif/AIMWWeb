import React from 'react';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const COMMENTS_BACK_TO_EXPLORER_OPERATION_ID = 'AIMW-COMM-2B682F7BEC';

type ActiveSite = {
    id: number;
    name?: string;
    status?: string;
};

type SiteAwareFrontendContext = FrontendContext & {
    active_site?: ActiveSite | null;
};

const commentsRoute = workspaceRoutes.find((route) => route.key === 'comments');
const explorerRoute = workspaceRoutes.find((route) => route.key === 'explorer');

export function authoritativeCommentsExplorerHref(context: FrontendContext): string | null {
    const siteAware = context as SiteAwareFrontendContext;
    const siteId = siteAware.active_site?.id;

    if (!commentsRoute || !explorerRoute || !context.permissions.includes('tenant.view')) return null;
    if (resolveCapability(context, commentsRoute).state !== 'enabled') return null;
    if (resolveCapability(context, explorerRoute).state !== 'enabled') return null;
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

    return `${tenantUrl(context.tenant.slug, '/explorer')}?site=${encodeURIComponent(String(siteId))}`;
}

export function CommentsBackToExplorerControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const href = authoritativeCommentsExplorerHref(context);

    if (!href) return null;

    return (
        <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'العودة إلى مستكشف الموقع' : 'Back to site explorer'}>
            <div className="toolbar-actions">
                <a
                    className="btn compact"
                    href={href}
                    data-canonical-operation={COMMENTS_BACK_TO_EXPLORER_OPERATION_ID}
                >
                    {locale === 'ar' ? 'العودة إلى المستكشف' : 'Back to Explorer'}
                </a>
            </div>
        </nav>
    );
}
