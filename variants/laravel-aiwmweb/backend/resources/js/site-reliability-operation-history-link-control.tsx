import React from 'react';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SITE_RELIABILITY_OPERATION_HISTORY_LINK_OPERATION_ID = 'AIMW-CONT-F4CBF0D714';

export function SiteReliabilityOperationHistoryLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const operationHistoryRoute = workspaceRoutes.find((route) => route.key === 'site-operations');
    const activeTenantIsAuthoritative = Boolean(
        context.tenant.slug
        && context.tenants.some((tenant) => tenant.slug === context.tenant.slug)
        && Number.isSafeInteger(context.user.id)
        && context.user.id > 0,
    );
    const routeContractIsExact = Boolean(
        operationHistoryRoute
        && operationHistoryRoute.path === '/site-operations'
        && operationHistoryRoute.permission === 'execution.view',
    );
    const capability = operationHistoryRoute
        ? resolveCapability(context, operationHistoryRoute)
        : { state: 'pending_integration' as const };

    if (!activeTenantIsAuthoritative || !operationHistoryRoute || !routeContractIsExact || capability.state !== 'enabled') {
        return null;
    }

    return (
        <a
            className="btn site-reliability-operation-history-link-control"
            href={tenantUrl(context.tenant.slug, operationHistoryRoute.path)}
            data-canonical-operation={SITE_RELIABILITY_OPERATION_HISTORY_LINK_OPERATION_ID}
        >
            {locale === 'ar' ? 'سجل العمليات' : 'Operation history'}
        </a>
    );
}
