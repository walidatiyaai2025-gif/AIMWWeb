import React from 'react';
import { Link } from 'react-router-dom';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const GLOBAL_MEDIA_OPEN_SYNC_OPERATION_ID = 'AIMW-SYNC-2A256826D2';

const mediaRoute = workspaceRoutes.find((route) => route.key === 'media');
const syncRoute = workspaceRoutes.find((route) => route.key === 'sync');

// The source GlobalMediaExplorer links to /module/sync. Keep navigation inside
// the active Laravel tenant and never bypass disabled/unauthorized workspaces.
export function canOpenGlobalMediaSync(context: FrontendContext, mediaAvailable: boolean): boolean {
    if (!mediaAvailable || !context.tenant.slug.trim() || !mediaRoute || !syncRoute) return false;
    const tenantAuthorized = context.permissions.includes('*') || context.permissions.includes('tenant.view');
    return tenantAuthorized
        && resolveCapability(context, mediaRoute).state === 'enabled'
        && resolveCapability(context, syncRoute).state === 'enabled';
}

export function GlobalMediaSyncLinkControl({
    context,
    mediaAvailable,
}: {
    context: FrontendContext;
    mediaAvailable: boolean;
}) {
    const { locale } = useLocale();
    if (!syncRoute || !canOpenGlobalMediaSync(context, mediaAvailable)) return null;

    return (
        <Link
            className="btn primary"
            to={tenantUrl(context.tenant.slug, syncRoute.path)}
            data-canonical-operation={GLOBAL_MEDIA_OPEN_SYNC_OPERATION_ID}
        >
            {locale === 'ar' ? 'فتح المزامنة' : 'Open synchronization'}
        </Link>
    );
}
