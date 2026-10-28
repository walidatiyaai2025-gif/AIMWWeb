import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const GLOBAL_SYNCHRONIZATION_EDITOR_LINK_OPERATION_ID = 'AIMW-SYNC-0239B0E6A3';

export type SyncConflictEditorTarget = {
    kind: 'RemoteUpdated' | 'RemoteDeleted';
    content_type: 'post' | 'page';
    wordpress_id: number;
};

export function globalSyncEditorDestination(
    tenantSlug: string,
    siteId: number | string,
    conflict: SyncConflictEditorTarget,
): string | null {
    const site = String(siteId);
    if (!tenantSlug.trim() || /[\\/]/.test(tenantSlug)) return null;
    if (!/^[1-9]\d*$/.test(site)) return null;
    if (conflict.kind !== 'RemoteUpdated') return null;
    if (conflict.content_type !== 'post' && conflict.content_type !== 'page') return null;
    if (!Number.isSafeInteger(conflict.wordpress_id) || conflict.wordpress_id < 1) return null;
    return tenantUrl(tenantSlug, `/sites/${site}/content/${conflict.content_type}/${conflict.wordpress_id}/edit`);
}

export function GlobalSynchronizationEditorLinkControl({
    context,
    siteId,
    conflict,
}: {
    context: FrontendContext;
    siteId: number;
    conflict: SyncConflictEditorTarget;
}) {
    const { locale } = useLocale();
    const authorized = context.permissions.includes('*')
        || (context.permissions.includes('tenant.view') && context.permissions.includes('content.view'));
    const destination = authorized
        ? globalSyncEditorDestination(context.tenant.slug, siteId, conflict)
        : null;

    if (!destination) return null;

    return (
        <Link
            className="btn primary compact"
            to={destination}
            data-canonical-operation={GLOBAL_SYNCHRONIZATION_EDITOR_LINK_OPERATION_ID}
        >
            {locale === 'ar' ? 'فتح المحرر للدمج' : 'Open editor to merge'}
        </Link>
    );
}
