import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiRequest, resolveCapability, workspaceRoutes, type FrontendContext } from './core';
import { safeContentExplorerExternalUrl } from './content-explorer-wordpress-link-control';
import { useLocale } from './i18n';

export const CONTENT_EXPLORER_MEDIA_SOURCE_LINK_OPERATION_ID = 'AIMW-CONT-B44DA81C28';

type SiteAwareFrontendContext = FrontendContext & {
    active_site?: { id: number; name?: string; status?: string } | null;
};

type MediaRow = {
    id?: number | string | null;
    remote_id?: number | string | null;
    title?: string | null;
    mime_type?: string | null;
    source_url?: string | null;
};

type MediaEnvelope = {
    data?: MediaRow[];
};

const mediaRoute = workspaceRoutes.find((route) => route.key === 'media');
const explorerRoute = workspaceRoutes.find((route) => route.key === 'explorer');

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

export function authoritativeContentExplorerMediaEndpoint(context: FrontendContext): string | null {
    const siteId = (context as SiteAwareFrontendContext).active_site?.id;

    if (!mediaRoute || !explorerRoute) return null;
    if (!hasPermission(context, 'tenant.view') || !hasPermission(context, 'sites.view')) return null;
    if (resolveCapability(context, explorerRoute).state !== 'enabled') return null;
    if (resolveCapability(context, mediaRoute).state !== 'enabled') return null;
    if (!Number.isSafeInteger(siteId) || Number(siteId) <= 0) return null;

    const endpoint = context.api.media;
    if (!endpoint) return null;

    try {
        const resolved = new URL(endpoint, window.location.origin);
        const expectedPath = '/api/v1/tenants/'
            + encodeURIComponent(context.tenant.slug)
            + '/sites/'
            + String(siteId)
            + '/media';

        if (resolved.origin !== window.location.origin) return null;
        if (resolved.pathname !== expectedPath || resolved.search || resolved.hash) return null;

        return resolved.pathname;
    } catch {
        return null;
    }
}

export function contentExplorerMediaSourcePageEndpoint(endpoint: string): string {
    const url = new URL(endpoint, window.location.origin);
    url.searchParams.set('per_page', '100');
    url.searchParams.set('page', '1');
    return url.pathname + url.search;
}

export function ContentExplorerMediaSourceLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const endpoint = authoritativeContentExplorerMediaEndpoint(context);
    const query = useQuery({
        queryKey: ['content-explorer-media-source-links', context.tenant.slug, endpoint],
        queryFn: () => apiRequest<MediaEnvelope>(contentExplorerMediaSourcePageEndpoint(endpoint!)),
        enabled: Boolean(endpoint),
        retry: false,
    });

    if (!endpoint) return null;

    const rows = Array.isArray(query.data?.data) ? query.data.data : [];

    return (
        <section
            className="panel data-panel content-explorer-media-source-links"
            aria-label={locale === 'ar' ? 'روابط ملفات الوسائط' : 'Media file links'}
        >
            <header className="panel-header">
                <div>
                    <span className="workspace-kicker">WORDPRESS MEDIA</span>
                    <strong>{locale === 'ar' ? 'فتح الملف الأصلي' : 'Open original file'}</strong>
                </div>
            </header>

            {query.isLoading ? (
                <p>{locale === 'ar' ? 'جارٍ تحميل الوسائط…' : 'Loading media…'}</p>
            ) : query.error ? (
                <p role="alert">
                    {locale === 'ar' ? 'تعذر تحميل روابط الوسائط.' : 'Media links could not be loaded.'}
                </p>
            ) : rows.length === 0 ? (
                <p>{locale === 'ar' ? 'لا توجد وسائط متاحة.' : 'No media is available.'}</p>
            ) : (
                <div className="table-scroll">
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>{locale === 'ar' ? 'الملف' : 'File'}</th>
                                <th>{locale === 'ar' ? 'النوع' : 'Type'}</th>
                                <th>{locale === 'ar' ? 'الإجراء' : 'Action'}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row, index) => {
                                const safeUrl = safeContentExplorerExternalUrl(row.source_url);
                                const title = typeof row.title === 'string' && row.title.trim()
                                    ? row.title
                                    : '#' + String(row.remote_id ?? row.id ?? index + 1);
                                const key = String(row.remote_id ?? row.id ?? index);

                                return (
                                    <tr key={key}>
                                        <td>{title}</td>
                                        <td>{typeof row.mime_type === 'string' ? row.mime_type : '—'}</td>
                                        <td>
                                            {safeUrl ? (
                                                <a
                                                    className="btn compact"
                                                    href={safeUrl}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    data-canonical-operation={CONTENT_EXPLORER_MEDIA_SOURCE_LINK_OPERATION_ID}
                                                    aria-label={
                                                        locale === 'ar'
                                                            ? 'فتح ملف ' + title
                                                            : 'Open file ' + title
                                                    }
                                                >
                                                    {locale === 'ar' ? 'فتح الملف' : 'Open file'} ↗
                                                </a>
                                            ) : (
                                                <span
                                                    aria-label={
                                                        locale === 'ar'
                                                            ? 'رابط ملف الوسائط غير متاح'
                                                            : 'Media file link unavailable'
                                                    }
                                                >
                                                    —
                                                </span>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
