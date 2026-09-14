import React from 'react';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';

export const CURRENT_USER_LOGS_OPERATION_ID = 'AIMW-IDEN-CD4ADA5087';

export function CurrentUserLogsControl({ context }: { context: FrontendContext }) {
    const logsRoute = workspaceRoutes.find((route) => route.key === 'logs');
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

    if (!logsRoute || !routeContractIsExact || !apiContractIsExact || !hasDiagnosticsPermission || !hasEndpointPermission || capability.state !== 'enabled') {
        return null;
    }

    return (
        <a
            className="btn current-user-logs-control"
            href={tenantUrl(context.tenant.slug, logsRoute.path)}
            data-canonical-operation={CURRENT_USER_LOGS_OPERATION_ID}
        >
            Open logs
        </a>
    );
}
