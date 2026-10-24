import React from 'react';
import { Link } from 'react-router-dom';
import {
    resolveCapability,
    tenantUrl,
    workspaceRoutes,
    type FrontendContext,
} from './core';
import { useLocale } from './i18n';

export const OPERATIONS_HUB_SITE_OPERATIONS_OPERATION_ID = 'AIMW-CONT-BCAD1E3A49';

export function OperationsHubSiteOperationsControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const route = workspaceRoutes.find((candidate) => candidate.key === 'site-operations');
    const activeTenantIsAuthoritative = Boolean(
        context.tenant.slug
        && context.tenants.some((tenant) => tenant.slug === context.tenant.slug)
        && Number.isSafeInteger(context.user.id)
        && context.user.id > 0,
    );
    const expectedApi = `/tenants/${encodeURIComponent(context.tenant.slug)}/route-api/site-operations`;
    const routeContractIsExact = Boolean(
        route
        && route.path === '/site-operations'
        && route.group === 'operations'
        && route.permission === 'execution.view'
        && route.apiKey === 'site-operations',
    );
    const apiContractIsExact = context.api['site-operations'] === expectedApi;
    const capability = route ? resolveCapability(context, route) : { state: 'pending_integration' as const };

    if (
        !activeTenantIsAuthoritative
        || !route
        || !routeContractIsExact
        || !apiContractIsExact
        || capability.state !== 'enabled'
    ) {
        return null;
    }

    return (
        <Link
            className="workspace-card"
            to={tenantUrl(context.tenant.slug, route.path)}
            data-canonical-operation={OPERATIONS_HUB_SITE_OPERATIONS_OPERATION_ID}
        >
            <span className="workspace-card-icon" aria-hidden="true">{route.icon}</span>
            <div>
                <strong>{route.label[locale]}</strong>
                <p>{route.description[locale]}</p>
                <small className="capability-pill capability-enabled">enabled</small>
            </div>
        </Link>
    );
}
