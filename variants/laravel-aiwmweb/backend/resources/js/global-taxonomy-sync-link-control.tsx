import React from 'react';
import { Link } from 'react-router-dom';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const GLOBAL_TAXONOMY_SYNC_OPERATION_ID = 'AIMW-SYNC-2C6ADCE34C';

const taxonomyRoute = workspaceRoutes.find((route) => route.key === 'taxonomy');
const syncRoute = workspaceRoutes.find((route) => route.key === 'sync');

export const canOpenGlobalTaxonomySync = (context: FrontendContext): boolean => {
    const hasPermission = (permission: string) =>
        context.permissions.includes('*') || context.permissions.includes(permission);

    return hasPermission('tenant.view')
        && Boolean(taxonomyRoute)
        && Boolean(syncRoute)
        && resolveCapability(context, taxonomyRoute!).state === 'enabled'
        && resolveCapability(context, syncRoute!).state === 'enabled';
};

export function GlobalTaxonomySyncLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    if (!syncRoute || !canOpenGlobalTaxonomySync(context)) return null;

    return (
        <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'الانتقال إلى المزامنة' : 'Synchronization navigation'}>
            <div className="toolbar-actions">
                <Link
                    className="btn primary"
                    to={tenantUrl(context.tenant.slug, syncRoute.path)}
                    data-canonical-operation={GLOBAL_TAXONOMY_SYNC_OPERATION_ID}
                >
                    {locale === 'ar' ? 'فتح المزامنة' : 'Open synchronization'}
                </Link>
            </div>
        </nav>
    );
}
