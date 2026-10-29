import React from 'react';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const OPERATIONS_HUB_SITES_LINK_OPERATION_ID = 'AIMW-CONT-7EF5524637';

// Navigation only: destination performs the authoritative tenant-scoped Sites read.
export function OperationsHubSitesLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const permitted = context.permissions.includes('*')
        || ['tenant.view', 'sites.view', 'execution.view'].every((permission) => context.permissions.includes(permission));
    if (!permitted) return null;

    return (
        <a className="btn" data-canonical-operation={OPERATIONS_HUB_SITES_LINK_OPERATION_ID}
            href={tenantUrl(context.tenant.slug, '/sites')}>
            {locale === 'ar' ? 'إدارة المواقع' : 'Manage sites'}
        </a>
    );
}
