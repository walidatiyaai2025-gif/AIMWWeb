import React from 'react';
import { Link } from 'react-router-dom';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SITE_OPERATIONS_SITES_LINK_OPERATION_ID = 'AIMW-CONT-5BE26C7AB5';

export function SiteOperationsSitesLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const sitesRoute = workspaceRoutes.find((route) => route.key === 'sites');
    const activeTenantIsAuthoritative = Boolean(
        context.tenant.slug
        && context.tenants.some((tenant) => tenant.slug === context.tenant.slug)
        && Number.isSafeInteger(context.user.id)
        && context.user.id > 0,
    );
    const canViewTenant = context.permissions.includes('tenant.view') || context.permissions.includes('*');
    const routeContractIsExact = Boolean(
        sitesRoute
        && sitesRoute.path === '/sites'
        && sitesRoute.permission === 'sites.view',
    );
    const capability = sitesRoute ? resolveCapability(context, sitesRoute) : { state: 'pending_integration' as const };

    if (!activeTenantIsAuthoritative || !canViewTenant || !sitesRoute || !routeContractIsExact || capability.state !== 'enabled') {
        return null;
    }

    return (
        <Link
            className="btn primary"
            to={tenantUrl(context.tenant.slug, sitesRoute.path)}
            data-canonical-operation={SITE_OPERATIONS_SITES_LINK_OPERATION_ID}
        >
            {locale === 'ar' ? 'إدارة المواقع' : 'Manage sites'}
        </Link>
    );
}
