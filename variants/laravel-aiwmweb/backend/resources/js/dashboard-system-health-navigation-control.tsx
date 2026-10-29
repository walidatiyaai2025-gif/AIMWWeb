import React from 'react';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const DASHBOARD_SYSTEM_HEALTH_NAVIGATION_OPERATION_ID = 'AIMW-CONT-A3A2CD23B6';

export function DashboardSystemHealthNavigationControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const systemHealthRoute = workspaceRoutes.find((route) => route.key === 'system-health');
    const activeTenantIsAuthoritative = Boolean(
        context.tenant.slug
        && context.tenants.some((tenant) => tenant.slug === context.tenant.slug)
        && Number.isSafeInteger(context.user.id)
        && context.user.id > 0,
    );
    const routeContractIsExact = Boolean(
        systemHealthRoute
        && systemHealthRoute.path === '/system-health'
        && systemHealthRoute.permission === 'diagnostics.view',
    );
    const capability = systemHealthRoute
        ? resolveCapability(context, { ...systemHealthRoute, apiKey: undefined })
        : { state: 'pending_integration' as const };

    if (!activeTenantIsAuthoritative || !systemHealthRoute || !routeContractIsExact || capability.state !== 'enabled') {
        return null;
    }

    return (
        <a
            className="btn dashboard-system-health-navigation-control"
            href={tenantUrl(context.tenant.slug, systemHealthRoute.path)}
            data-canonical-operation={DASHBOARD_SYSTEM_HEALTH_NAVIGATION_OPERATION_ID}
        >
            <span aria-hidden="true">♥</span>
            {locale === 'ar' ? 'صحة النظام' : 'System health'}
        </a>
    );
}
