import React from 'react';
import { Link } from 'react-router-dom';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const GLOBAL_SYNCHRONIZATION_LOCAL_CONTENT_OPERATION_ID = 'AIMW-SYNC-E51F091C0B';

type ContextWithActiveSite = FrontendContext & {
    active_site?: { id: number; name: string } | null;
};

const syncRoute = workspaceRoutes.find((route) => route.key === 'sync');
const explorerRoute = workspaceRoutes.find((route) => route.key === 'explorer');

export function globalSynchronizationLocalContentDestination(context: FrontendContext): string | null {
    const permissions = context.permissions;
    const authorized = (permission: string) => permissions.includes('*') || permissions.includes(permission);
    const site = (context as ContextWithActiveSite).active_site;

    if (!authorized('tenant.view') || !syncRoute || !explorerRoute) return null;
    if (resolveCapability(context, syncRoute).state !== 'enabled') return null;
    if (resolveCapability(context, explorerRoute).state !== 'enabled') return null;
    if (!site || !Number.isSafeInteger(site.id) || site.id <= 0) return null;
    if (!context.tenant.slug.trim()) return null;

    return tenantUrl(context.tenant.slug, explorerRoute.path)
        + '?site=' + encodeURIComponent(String(site.id));
}

export function GlobalSynchronizationLocalContentLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const destination = globalSynchronizationLocalContentDestination(context);
    if (!destination) return null;

    return (
        <nav
            className="toolbar-panel"
            aria-label={locale === 'ar' ? 'المحتوى المحلي للموقع المحدد' : 'Selected site local content'}
            data-canonical-operation={GLOBAL_SYNCHRONIZATION_LOCAL_CONTENT_OPERATION_ID}
        >
            <div className="toolbar-actions">
                <Link className="btn" to={destination}>
                    {locale === 'ar' ? 'فتح المحتوى المحلي' : 'Open local content'}
                </Link>
            </div>
        </nav>
    );
}
