import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

// Source parity: SiteOperationsOverview.razor Manage sites navigation to the tenant Sites workspace.
export const SITE_OPERATIONS_SITES_LINK_OPERATION_ID = 'AIMW-CONT-5BE26C7AB5';

export function SiteOperationsSitesLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const target = tenantUrl(context.tenant.slug, '/sites');

    return (
        <Link
            className="btn primary"
            data-canonical-operation={SITE_OPERATIONS_SITES_LINK_OPERATION_ID}
            to={target}
        >
            {locale === 'ar' ? 'إدارة المواقع' : 'Manage sites'}
        </Link>
    );
}
