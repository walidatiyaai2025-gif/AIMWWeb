import React from 'react';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SITE_OPERATIONS_DETAILS_LINK_OPERATION_ID = 'AIMW-CONT-4147188AB1';

type SiteOperationRow = Record<string, unknown>;

const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

export function authoritativeSiteOperationDetailsHref(
    context: FrontendContext,
    row: SiteOperationRow,
): string | null {
    const correlationId = typeof row.correlation_id === 'string' ? row.correlation_id.trim() : '';
    const activeTenantIsAuthoritative = Boolean(
        context.tenant.slug
        && context.tenants.some((tenant) => tenant.slug === context.tenant.slug),
    );

    if (!activeTenantIsAuthoritative || !hasPermission(context, 'execution.view') || !UUID_PATTERN.test(correlationId)) {
        return null;
    }

    return tenantUrl(context.tenant.slug, `/operations/sites/${encodeURIComponent(correlationId)}`);
}

export function SiteOperationsDetailsLinkControl({
    context,
    row,
}: {
    context: FrontendContext;
    row: SiteOperationRow;
}) {
    const { locale } = useLocale();
    const href = authoritativeSiteOperationDetailsHref(context, row);

    if (!href) return null;

    return (
        <a
            className="btn compact"
            data-canonical-operation={SITE_OPERATIONS_DETAILS_LINK_OPERATION_ID}
            href={href}
        >
            {locale === 'ar' ? 'تفاصيل' : 'Details'}
        </a>
    );
}
