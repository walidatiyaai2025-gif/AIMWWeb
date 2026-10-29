import React from 'react';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CONTENT_EXPLORER_TAGS_TAB_OPERATION_ID = 'AIMW-TAXO-D6126926AD';

type SiteAwareFrontendContext = FrontendContext & {
    active_site?: { id: number; name?: string; status?: string } | null;
};

const taxonomyRoute = workspaceRoutes.find((route) => route.key === 'taxonomy');

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

export function requestedTaxonomyTypeFromLocation(): 'category' | 'post_tag' {
    return new URLSearchParams(window.location.search).get('taxonomy') === 'post_tag' ? 'post_tag' : 'category';
}

export function authoritativeContentExplorerTagsHref(context: FrontendContext): string | null {
    const siteId = (context as SiteAwareFrontendContext).active_site?.id;
    if (!taxonomyRoute || !hasPermission(context, 'tenant.view') || !hasPermission(context, 'sites.view')) return null;
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

    return `${tenantUrl(context.tenant.slug, taxonomyRoute.path)}?site=${encodeURIComponent(String(siteId))}&taxonomy=post_tag`;
}

export function ContentExplorerTagsTabControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const href = authoritativeContentExplorerTagsHref(context);
    if (!href) return null;

    return (
        <a
            className="explorer-tab"
            href={href}
            data-canonical-operation={CONTENT_EXPLORER_TAGS_TAB_OPERATION_ID}
        >
            ⌗ {locale === 'ar' ? 'الوسوم' : 'Tags'}
        </a>
    );
}
