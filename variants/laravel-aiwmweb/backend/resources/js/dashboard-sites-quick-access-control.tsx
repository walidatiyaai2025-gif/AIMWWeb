import React from 'react';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const DASHBOARD_SITES_QUICK_ACCESS_OPERATION_ID = 'AIMW-CONT-7957ECC794';

export function DashboardSitesQuickAccessControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const sitesRoute = workspaceRoutes.find((route) => route.key === 'sites');
    const activeTenantIsAuthoritative = Boolean(
        context.tenant.slug
        && context.tenants.some((tenant) => tenant.slug === context.tenant.slug)
        && Number.isSafeInteger(context.user.id)
        && context.user.id > 0,
    );
    const encodedTenant = encodeURIComponent(context.tenant.slug);
    const expectedSitesApi = `/api/tenants/${encodedTenant}/sites`;
    const routeContractIsExact = Boolean(
        sitesRoute
        && sitesRoute.path === '/sites'
        && sitesRoute.permission === 'sites.view'
        && sitesRoute.apiKey === 'sites',
    );
    const apiContractIsExact = context.api.sites === expectedSitesApi;
    const capability = sitesRoute ? resolveCapability(context, sitesRoute) : { state: 'pending_integration' as const };

    if (
        !activeTenantIsAuthoritative
        || !sitesRoute
        || !routeContractIsExact
        || !apiContractIsExact
        || capability.state !== 'enabled'
    ) {
        return null;
    }

    return (
        <a
            className="btn dashboard-sites-quick-access-control"
            href={tenantUrl(context.tenant.slug, sitesRoute.path)}
            data-canonical-operation={DASHBOARD_SITES_QUICK_ACCESS_OPERATION_ID}
            data-source-control="dashboard-quick-access-sites"
        >
            <span aria-hidden="true">◉</span>
            {locale === 'ar' ? 'المواقع' : 'Sites'}
        </a>
    );
}
