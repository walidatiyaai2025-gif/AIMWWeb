import React from 'react';
import { type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CONTENT_EXPLORER_CONTENT_TAB_OPERATION_ID = 'AIMW-CONT-93B6E02ACF';
export const CONTENT_EXPLORER_CONTENT_TAB_RESET_EVENT = 'aiwm:content-explorer:content-tab';

type SiteAwareFrontendContext = FrontendContext & {
    active_site?: { id: number; name?: string; status?: string } | null;
};

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

export function canSelectAuthoritativeContentExplorerTab(context: FrontendContext): boolean {
    const siteId = (context as SiteAwareFrontendContext).active_site?.id;
    if (!hasPermission(context, 'tenant.view') || !hasPermission(context, 'sites.view')) return false;
    if (!Number.isSafeInteger(siteId) || Number(siteId) <= 0) return false;

    const endpoint = context.api.explorer;
    if (!endpoint) return false;

    try {
        const resolved = new URL(endpoint, window.location.origin);
        const expectedPath = `/api/v1/tenants/${encodeURIComponent(context.tenant.slug)}/sites/${siteId}/explorer`;
        return resolved.origin === window.location.origin
            && resolved.pathname === expectedPath
            && resolved.search === ''
            && resolved.hash === '';
    } catch {
        return false;
    }
}

export function ContentExplorerContentTabControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    if (!canSelectAuthoritativeContentExplorerTab(context)) return null;

    const selectContent = () => {
        window.dispatchEvent(new Event(CONTENT_EXPLORER_CONTENT_TAB_RESET_EVENT));
    };

    return (
        <button
            type="button"
            className="explorer-tab active"
            aria-pressed="true"
            data-canonical-operation={CONTENT_EXPLORER_CONTENT_TAB_OPERATION_ID}
            onClick={selectContent}
        >
            ▤ {locale === 'ar' ? 'المحتوى' : 'Content'}
        </button>
    );
}
