import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';
import { normalizeAuthoritativeSiteId, sitesInterpolatedDestinations } from './sites-interpolated-links-control';

export const CONTENT_HUB_SITE_LINKS_OPERATION_ID = 'AIMW-CONT-276925F148';

type SiteRow = {
    id: string;
    name: string;
};

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

export function authoritativeContentHubSitesEndpoint(context: FrontendContext): string | null {
    const endpoint = context.api.sites;
    if (!endpoint || !context.tenant.slug) return null;

    try {
        const resolved = new URL(endpoint, window.location.origin);
        const expectedPath = `/api/tenants/${encodeURIComponent(context.tenant.slug)}/sites`;

        if (resolved.origin !== window.location.origin) return null;
        if (resolved.pathname !== expectedPath || resolved.search || resolved.hash) return null;

        return resolved.pathname;
    } catch {
        return null;
    }
}

export function normalizeContentHubSiteRows(payload: unknown): SiteRow[] {
    const envelope = payload && typeof payload === 'object' && !Array.isArray(payload)
        ? payload as { data?: unknown; items?: unknown }
        : null;
    const rows = Array.isArray(payload)
        ? payload
        : Array.isArray(envelope?.data)
            ? envelope.data
            : Array.isArray(envelope?.items)
                ? envelope.items
                : [];

    const seen = new Set<string>();
    const normalized: SiteRow[] = [];

    for (const candidate of rows) {
        if (!candidate || typeof candidate !== 'object' || Array.isArray(candidate)) continue;

        const row = candidate as Record<string, unknown>;
        const id = normalizeAuthoritativeSiteId(row.id);
        const name = typeof row.name === 'string' ? row.name.trim() : '';

        if (!id || !name || seen.has(id)) continue;
        seen.add(id);
        normalized.push({ id, name });
    }

    return normalized;
}

export function ContentHubSiteLinksControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const endpoint = authoritativeContentHubSitesEndpoint(context);
    const canRead = hasPermission(context, 'tenant.view')
        && hasPermission(context, 'content.view')
        && hasPermission(context, 'sites.view');

    const query = useQuery({
        queryKey: ['content-hub-site-links', context.tenant.slug, endpoint],
        queryFn: () => apiRequest<unknown>(endpoint!),
        enabled: canRead && Boolean(endpoint),
        staleTime: 20_000,
    });

    if (!canRead || !endpoint) return null;

    if (query.isLoading) {
        return (
            <section
                className="panel"
                aria-live="polite"
                data-canonical-operation={CONTENT_HUB_SITE_LINKS_OPERATION_ID}
            >
                <p>{locale === 'ar' ? 'جارٍ تحميل مواقع المحتوى…' : 'Loading content sites…'}</p>
            </section>
        );
    }

    if (query.error) {
        return (
            <section
                className="panel"
                role="alert"
                data-canonical-operation={CONTENT_HUB_SITE_LINKS_OPERATION_ID}
            >
                <strong>{locale === 'ar' ? 'تعذر تحميل المواقع' : 'Sites could not be loaded'}</strong>
                <p>{query.error instanceof Error ? query.error.message : (locale === 'ar' ? 'فشل طلب المواقع.' : 'The sites request failed.')}</p>
                <button type="button" className="btn" onClick={() => void query.refetch()}>
                    {locale === 'ar' ? 'إعادة المحاولة' : 'Retry'}
                </button>
            </section>
        );
    }

    const sites = normalizeContentHubSiteRows(query.data);

    return (
        <section
            className="panel"
            aria-label={locale === 'ar' ? 'روابط مواقع مركز المحتوى' : 'Content hub site links'}
            data-canonical-operation={CONTENT_HUB_SITE_LINKS_OPERATION_ID}
        >
            <div className="panel-header">
                <div>
                    <span className="workspace-kicker">CONTENT SITES</span>
                    <h3>{locale === 'ar' ? 'المواقع المتاحة' : 'Available sites'}</h3>
                    <p>{locale === 'ar'
                        ? 'روابط الموقع والمستكشف تُبنى فقط من معرّفات المواقع التي أعادها الخادم للحساب الحالي.'
                        : 'Site and Explorer links are derived only from site identifiers returned by the active tenant API.'}</p>
                </div>
            </div>

            {sites.length === 0 ? (
                <p>{locale === 'ar' ? 'لم يعِد الخادم أي مواقع متاحة.' : 'No accessible sites were returned.'}</p>
            ) : (
                <div className="table-scroll" role="region" aria-label={locale === 'ar' ? 'مواقع المحتوى' : 'Content sites'}>
                    <table className="data-table">
                        <thead>
                            <tr>
                                <th>{locale === 'ar' ? 'الموقع' : 'Site'}</th>
                                <th>{locale === 'ar' ? 'المحتوى' : 'Content'}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {sites.map((site) => {
                                const destinations = sitesInterpolatedDestinations(context.tenant.slug, site.id);
                                if (!destinations) return null;

                                return (
                                    <tr key={site.id}>
                                        <td>
                                            <a
                                                href={destinations.dashboard}
                                                data-canonical-operation={CONTENT_HUB_SITE_LINKS_OPERATION_ID}
                                                data-source-control="site-details"
                                            >
                                                <strong>{site.name}</strong>
                                            </a>
                                        </td>
                                        <td>
                                            <a
                                                className="btn compact primary"
                                                href={destinations.explorer}
                                                data-canonical-operation={CONTENT_HUB_SITE_LINKS_OPERATION_ID}
                                                data-source-control="site-explorer"
                                            >
                                                {locale === 'ar' ? 'المحتوى' : 'Content'}
                                            </a>
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
