import React from 'react';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';
import type { ContentExplorerPreviewRow } from './content-explorer-preview-panel';

export const CONTENT_EXPLORER_PREVIEW_EDITOR_LINK_OPERATION_ID = 'AIMW-CONT-9BA2C255AF';

type SiteAwareFrontendContext = FrontendContext & {
    active_site?: { id: number; name?: string; status?: string } | null;
};

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

function positiveSafeInteger(value: unknown): number | null {
    const parsed = typeof value === 'number'
        ? value
        : typeof value === 'string' && /^[1-9]\d*$/.test(value.trim())
            ? Number(value)
            : Number.NaN;

    return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : null;
}

function authoritativeExplorerSiteId(context: FrontendContext): number | null {
    if (!hasPermission(context, 'tenant.view') || !hasPermission(context, 'sites.view')) return null;

    const siteId = positiveSafeInteger((context as SiteAwareFrontendContext).active_site?.id);
    if (!siteId || !context.api.explorer) return null;

    try {
        const endpoint = new URL(context.api.explorer, window.location.origin);
        const expectedPath = `/api/v1/tenants/${encodeURIComponent(context.tenant.slug)}/sites/${siteId}/explorer`;

        if (endpoint.origin !== window.location.origin) return null;
        if (endpoint.pathname !== expectedPath || endpoint.search !== '' || endpoint.hash !== '') return null;

        return siteId;
    } catch {
        return null;
    }
}

export function contentExplorerPreviewEditorHref(
    context: FrontendContext,
    row: ContentExplorerPreviewRow,
): string | null {
    if (!hasPermission(context, 'content.view')) return null;

    const siteId = authoritativeExplorerSiteId(context);
    const wordpressId = positiveSafeInteger(row.wordpress_id);
    const contentType = typeof row.type === 'string' ? row.type.trim().toLowerCase() : '';

    if (!siteId || !wordpressId || !['post', 'page'].includes(contentType)) return null;

    return tenantUrl(
        context.tenant.slug,
        `/sites/${siteId}/content/${contentType}/${wordpressId}/edit`,
    );
}

export function ContentExplorerPreviewEditorLinkControl({
    context,
    row,
}: {
    context: FrontendContext;
    row: ContentExplorerPreviewRow;
}) {
    const { locale } = useLocale();
    const href = contentExplorerPreviewEditorHref(context, row);
    if (!href) return null;

    const canEdit = hasPermission(context, 'content.edit');

    return (
        <a
            className={canEdit ? 'btn primary' : 'btn'}
            href={href}
            data-canonical-operation={CONTENT_EXPLORER_PREVIEW_EDITOR_LINK_OPERATION_ID}
        >
            {canEdit
                ? (locale === 'ar' ? 'تحرير' : 'Edit')
                : (locale === 'ar' ? 'عرض' : 'View')}
        </a>
    );
}
