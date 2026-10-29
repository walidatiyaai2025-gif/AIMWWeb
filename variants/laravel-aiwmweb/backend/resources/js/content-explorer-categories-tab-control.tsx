import React from 'react';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CONTENT_EXPLORER_CATEGORIES_TAB_OPERATION_ID = 'AIMW-TAXO-A96714F338';

type SiteAwareFrontendContext = FrontendContext & {
    active_site?: { id: number; name?: string; status?: string } | null;
};

const taxonomyRoute = workspaceRoutes.find((route) => route.key === 'taxonomy');
const explorerRoute = workspaceRoutes.find((route) => route.key === 'explorer');

export function authoritativeContentExplorerCategoriesHref(context: FrontendContext): string | null {
    const siteId = (context as SiteAwareFrontendContext).active_site?.id;

    if (!taxonomyRoute || !explorerRoute || !context.permissions.includes('tenant.view')) return null;
    if (!context.permissions.includes('sites.view') && !context.permissions.includes('*')) return null;
    if (!context.permissions.includes('content.view') && !context.permissions.includes('*')) return null;
    if (resolveCapability(context, taxonomyRoute).state !== 'enabled') return null;
    if (!Number.isSafeInteger(siteId) || Number(siteId) <= 0) return null;

    const endpoint = context.api.taxonomy;
    if (!endpoint) return null;

    try {
        const resolved = new URL(endpoint, window.location.origin);
        const expectedPath = `/api/v1/tenants/${encodeURIComponent(context.tenant.slug)}/sites/${siteId}/taxonomy`;
        if (resolved.origin !== window.location.origin) return null;
        if (resolved.pathname !== expectedPath || resolved.search || resolved.hash) return null;
    } catch {
        return null;
    }

    return `${tenantUrl(context.tenant.slug, taxonomyRoute.path)}?site=${encodeURIComponent(String(siteId))}`;
}

export function ContentExplorerCategoriesTabControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const href = authoritativeContentExplorerCategoriesHref(context);

    if (!href) return null;

    return (
        <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'تبويبات مستكشف المحتوى' : 'Content Explorer tabs'}>
            <div className="toolbar-actions">
                <a
                    className="btn"
                    href={href}
                    data-canonical-operation={CONTENT_EXPLORER_CATEGORIES_TAB_OPERATION_ID}
                >
                    # {locale === 'ar' ? 'التصنيفات' : 'Categories'}
                </a>
            </div>
        </nav>
    );
}
