import React from 'react';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SYSTEM_HEALTH_OPEN_LOGS_OPERATION_ID = 'AIMW-CONT-553D999DDB';

export function SystemHealthOpenLogsControl({
    context,
    snapshotReady,
}: {
    context: FrontendContext;
    snapshotReady: boolean;
}) {
    const { locale } = useLocale();
    const logsRoute = workspaceRoutes.find((route) => route.key === 'logs');
    const activeTenantIsAuthoritative = Boolean(
        context.tenant.slug
        && context.tenants.some((tenant) => tenant.slug === context.tenant.slug)
        && Number.isSafeInteger(context.user.id)
        && context.user.id > 0,
    );
    const encodedTenant = encodeURIComponent(context.tenant.slug);
    const expectedLogsApi = `/tenants/${encodedTenant}/admin/logs`;
    const hasDiagnosticsPermission = context.permissions.includes('diagnostics.view') || context.permissions.includes('*');
    const hasEndpointPermission = context.permissions.includes('operations.manage') || context.permissions.includes('*');
    const routeContractIsExact = Boolean(
        logsRoute
        && logsRoute.path === '/module/logs'
        && logsRoute.apiKey === 'logs'
        && logsRoute.permission === 'diagnostics.view',
    );
    const apiContractIsExact = context.api.logs === expectedLogsApi;
    const capability = logsRoute ? resolveCapability(context, logsRoute) : { state: 'pending_integration' as const };

    if (
        !snapshotReady
        || !activeTenantIsAuthoritative
        || !logsRoute
        || !routeContractIsExact
        || !apiContractIsExact
        || !hasDiagnosticsPermission
        || !hasEndpointPermission
        || capability.state !== 'enabled'
    ) {
        return null;
    }

    return (
        <a
            className="btn system-health-open-logs-control"
            href={tenantUrl(context.tenant.slug, logsRoute.path)}
            data-canonical-operation={SYSTEM_HEALTH_OPEN_LOGS_OPERATION_ID}
        >
            {locale === 'ar' ? 'فتح السجلات' : 'Open logs'}
        </a>
    );
}
