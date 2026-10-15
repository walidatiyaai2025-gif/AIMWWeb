import React from 'react';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const DASHBOARD_SITES_NAVIGATION_OPERATION_ID = 'AIMW-CONT-71F54E3BA6';

export function DashboardSitesNavigationControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const sitesRoute = workspaceRoutes.find((route) => route.key === 'sites');
    const activeTenantIsAuthoritative = Boolean(
        context.tenant.slug
        && context.tenants.some((tenant) => tenant.slug === context.tenant.slug)
        && Number.isSafeInteger(context.user.id)
        && context.user.id > 0,
    );
    const routeContractIsExact = Boolean(
        sitesRoute
        && sitesRoute.path === '/sites'
        && sitesRoute.permission === 'sites.view',
    );
    const capability = sitesRoute ? resolveCapability(context, sitesRoute) : { state: 'pending_integration' as const };

    if (!activeTenantIsAuthoritative || !sitesRoute || !routeContractIsExact || capability.state !== 'enabled') {
        return null;
    }

    return (
        <a
            className="btn primary dashboard-sites-navigation-control"
            href={tenantUrl(context.tenant.slug, sitesRoute.path)}
            data-canonical-operation={DASHBOARD_SITES_NAVIGATION_OPERATION_ID}
        >
            {locale === 'ar' ? 'إضافة موقع' : 'Add site'}
        </a>
    );
}
