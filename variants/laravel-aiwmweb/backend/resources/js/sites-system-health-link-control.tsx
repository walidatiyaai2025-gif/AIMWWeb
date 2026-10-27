import React from 'react';
import { tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SITES_SYSTEM_HEALTH_LINK_OPERATION_ID = 'AIMW-CONT-97AD37042A';

export function SitesSystemHealthLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const systemHealthRoute = workspaceRoutes.find((route) => route.key === 'system-health');
    const activeTenantIsAuthoritative = Boolean(
        context.tenant.slug
        && context.tenants.some((tenant) => tenant.slug === context.tenant.slug)
        && Number.isSafeInteger(context.user.id)
        && context.user.id > 0,
    );
    const canViewTenant = context.permissions.includes('tenant.view') || context.permissions.includes('*');
    const canViewDiagnostics = context.permissions.includes('diagnostics.view') || context.permissions.includes('*');
    const routeContractIsExact = Boolean(
        systemHealthRoute
        && systemHealthRoute.path === '/system-health'
        && systemHealthRoute.permission === 'diagnostics.view',
    );

    if (!activeTenantIsAuthoritative || !canViewTenant || !canViewDiagnostics || !routeContractIsExact) {
        return null;
    }

    return (
        <a
            className="btn sites-system-health-link-control"
            href={tenantUrl(context.tenant.slug, '/system-health')}
            data-canonical-operation={SITES_SYSTEM_HEALTH_LINK_OPERATION_ID}
        >
            <span aria-hidden="true">♥</span>
            <span>{locale === 'ar' ? 'صحة النظام' : 'System health'}</span>
        </a>
    );
}
