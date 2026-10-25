import React, { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { apiRequest, tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CONTENT_EXPLORER_DYNAMIC_LINKS_OPERATION_ID = 'AIMW-CONT-9BA2C255AF';

type ActiveSite = {
    id: number;
    name: string;
};

type ContextWithActiveSite = FrontendContext & {
    active_site?: ActiveSite | null;
};

type ContentRow = {
    remote_id: number;
    type: 'post' | 'page';
    title?: string | null;
};

type ContentPage = {
    data?: Array<{
        remote_id?: number | string | null;
        title?: string | null;
    }>;
};

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

export function contentExplorerDynamicTargets(context: FrontendContext, siteId: number) {
    const safeSiteId = Number.isSafeInteger(siteId) && siteId > 0 ? String(siteId) : '';
    if (!safeSiteId) return null;

    return {
        site: tenantUrl(context.tenant.slug, `/sites/${encodeURIComponent(safeSiteId)}`),
        taxonomy: tenantUrl(
            context.tenant.slug,
            `/module/taxonomy?site=${encodeURIComponent(safeSiteId)}`,
        ),
        seo: tenantUrl(
            context.tenant.slug,
            `/module/seo-audit?site=${encodeURIComponent(safeSiteId)}`,
        ),
    };
}

export function contentExplorerEditorHref(
    context: FrontendContext,
    siteId: number,
    type: 'post' | 'page',
    remoteId: number,
): string | null {
    if (!Number.isSafeInteger(siteId) || siteId <= 0) return null;
    if (!Number.isSafeInteger(remoteId) || remoteId <= 0) return null;

    return tenantUrl(
        context.tenant.slug,
        `/sites/${encodeURIComponent(String(siteId))}/content/${type}/${encodeURIComponent(String(remoteId))}/edit`,
    );
}

function rows(page: ContentPage | undefined, type: 'post' | 'page'): ContentRow[] {
    if (!Array.isArray(page?.data)) return [];

    return page.data.flatMap((item) => {
        const remoteId = Number(item.remote_id);
        if (!Number.isSafeInteger(remoteId) || remoteId <= 0) return [];
        return [{ remote_id: remoteId, type, title: item.title ?? null }];
    });
}

export function ContentExplorerDynamicLinksControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const activeSite = (context as ContextWithActiveSite).active_site;
    const siteId = activeSite?.id ?? 0;
    const targets = contentExplorerDynamicTargets(context, siteId);
    const canViewSites = hasPermission(context, 'sites.view');
    const canViewContent = hasPermission(context, 'content.view');
    const canViewSeo = hasPermission(context, 'seo.view');
    const postsEndpoint = context.api.posts;
    const pagesEndpoint = context.api.pages;
    const queryEnabled = Boolean(activeSite && canViewContent && postsEndpoint && pagesEndpoint);

    const postsQuery = useQuery({
        queryKey: ['content-explorer-dynamic-links', context.tenant.slug, siteId, 'post'],
        queryFn: () => apiRequest<ContentPage>(`${postsEndpoint}?per_page=100`),
        enabled: queryEnabled,
    });
    const pagesQuery = useQuery({
        queryKey: ['content-explorer-dynamic-links', context.tenant.slug, siteId, 'page'],
        queryFn: () => apiRequest<ContentPage>(`${pagesEndpoint}?per_page=100`),
        enabled: queryEnabled,
    });

    const contentRows = useMemo(
        () => [...rows(postsQuery.data, 'post'), ...rows(pagesQuery.data, 'page')],
        [postsQuery.data, pagesQuery.data],
    );

    if (!activeSite || !targets || !canViewSites) return null;

    return (
        <section
            className="panel data-panel"
            data-canonical-operation={CONTENT_EXPLORER_DYNAMIC_LINKS_OPERATION_ID}
            aria-label={locale === 'ar' ? 'روابط مستكشف المحتوى' : 'Content explorer links'}
        >
            <header className="panel-header">
                <div>
                    <span className="workspace-kicker">CONTENT EXPLORER</span>
                    <h2>{activeSite.name}</h2>
                    <p>
                        {locale === 'ar'
                            ? 'روابط ديناميكية مشتقة من الموقع النشط داخل الحساب الحالي.'
                            : 'Dynamic destinations derived from the active site inside the current tenant.'}
                    </p>
                </div>
            </header>

            <nav className="toolbar-actions" aria-label={locale === 'ar' ? 'تنقل الموقع' : 'Site navigation'}>
                <Link className="btn" to={targets.site}>
                    {locale === 'ar' ? 'تفاصيل الموقع' : 'Site details'}
                </Link>
                {canViewContent ? (
                    <Link className="btn" to={targets.taxonomy}>
                        {locale === 'ar' ? 'إدارة التصنيفات' : 'Manage taxonomy'}
                    </Link>
                ) : null}
                {canViewSeo ? (
                    <Link className="btn" to={targets.seo}>SEO</Link>
                ) : null}
            </nav>

            {queryEnabled ? (
                <div className="table-scroll" role="region" aria-label={locale === 'ar' ? 'روابط تحرير المحتوى' : 'Content editor links'}>
                    {postsQuery.isLoading || pagesQuery.isLoading ? (
                        <p>{locale === 'ar' ? 'جارٍ تحميل المحتوى…' : 'Loading content…'}</p>
                    ) : null}
                    {postsQuery.error || pagesQuery.error ? (
                        <p role="alert">{String(postsQuery.error ?? pagesQuery.error)}</p>
                    ) : null}
                    {!postsQuery.isLoading && !pagesQuery.isLoading && !postsQuery.error && !pagesQuery.error && contentRows.length === 0 ? (
                        <p>{locale === 'ar' ? 'لا يوجد محتوى متاح.' : 'No content is available.'}</p>
                    ) : null}
                    {contentRows.length ? (
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>{locale === 'ar' ? 'العنوان' : 'Title'}</th>
                                    <th>{locale === 'ar' ? 'النوع' : 'Type'}</th>
                                    <th>{locale === 'ar' ? 'الإجراء' : 'Action'}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {contentRows.map((row) => {
                                    const href = contentExplorerEditorHref(context, siteId, row.type, row.remote_id);
                                    if (!href) return null;
                                    return (
                                        <tr key={`${row.type}:${row.remote_id}`}>
                                            <td>{row.title ?? `#${row.remote_id}`}</td>
                                            <td>{row.type}</td>
                                            <td>
                                                <Link className="btn compact" to={href}>
                                                    {locale === 'ar' ? 'فتح' : 'Open'}
                                                </Link>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    ) : null}
                </div>
            ) : null}
        </section>
    );
}
