import React from 'react';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const DASHBOARD_MEDIA_NAVIGATION_OPERATION_ID = 'AIMW-MEDI-799B36D113';

export function DashboardMediaNavigationControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const mediaRoute = workspaceRoutes.find((route) => route.key === 'media');
    const activeTenantIsAuthoritative = Boolean(
        context.tenant.slug
        && context.tenants.some((tenant) => tenant.slug === context.tenant.slug)
        && Number.isSafeInteger(context.user.id)
        && context.user.id > 0,
    );
    const routeContractIsExact = Boolean(
        mediaRoute
        && mediaRoute.path === '/module/media'
        && mediaRoute.permission === 'content.view'
        && mediaRoute.connectorScope === 'media.read'
        && mediaRoute.apiKey === 'media',
    );
    const capability = mediaRoute
        ? resolveCapability(context, { ...mediaRoute, apiKey: undefined, connectorScope: undefined })
        : { state: 'pending_integration' as const };

    if (!activeTenantIsAuthoritative || !mediaRoute || !routeContractIsExact || capability.state !== 'enabled') {
        return null;
    }

    return (
        <a
            className="btn dashboard-media-navigation-control"
            href={tenantUrl(context.tenant.slug, mediaRoute.path)}
            data-canonical-operation={DASHBOARD_MEDIA_NAVIGATION_OPERATION_ID}
            data-source-control="dashboard-quick-access-media"
        >
            <span aria-hidden="true">▣</span>
            {locale === 'ar' ? 'الوسائط' : 'Media'}
        </a>
    );
}
