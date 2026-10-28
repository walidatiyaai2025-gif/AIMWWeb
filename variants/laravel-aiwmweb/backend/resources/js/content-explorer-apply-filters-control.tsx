import React, { type FormEvent, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CONTENT_EXPLORER_APPLY_FILTERS_OPERATION_ID = 'AIMW-SYNC-419C403EFB';

type SiteAwareContext = FrontendContext & {
    active_site?: { id: number; name?: string; status?: string } | null;
};

type ExplorerRow = {
    id: number;
    wordpress_id: number | null;
    type: string;
    title: string;
    slug: string;
    status: string;
};

type ExplorerEnvelope = {
    data: ExplorerRow[];
    total: number;
    current_page: number;
    last_page: number;
};

type ExplorerFilters = {
    search: string;
    type: 'all' | 'post' | 'page';
    status: 'all' | 'publish' | 'draft' | 'pending' | 'future';
    perPage: number;
};

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

export function authoritativeContentExplorerFilterEndpoint(context: FrontendContext): string | null {
    const siteId = (context as SiteAwareContext).active_site?.id;
    const endpoint = context.api.explorer;

    if (!hasPermission(context, 'tenant.view') || !hasPermission(context, 'sites.view')) return null;
    if (!Number.isSafeInteger(siteId) || Number(siteId) <= 0 || !endpoint) return null;

    try {
        const resolved = new URL(endpoint, window.location.origin);
        const expectedPath = '/api/v1/tenants/' + encodeURIComponent(context.tenant.slug)
            + '/sites/' + String(siteId) + '/explorer';
        if (resolved.origin !== window.location.origin) return null;
        if (resolved.pathname !== expectedPath || resolved.search || resolved.hash) return null;
        return resolved.pathname;
    } catch {
        return null;
    }
}

export function contentExplorerFilterUrl(endpoint: string, filters: ExplorerFilters): string {
    const resolved = new URL(endpoint, window.location.origin);
    const params = new URLSearchParams();
    if (filters.search.trim()) params.set('search', filters.search.trim());
    params.set('type', filters.type);
    params.set('status', filters.status);
    params.set('per_page', String(filters.perPage));
    return resolved.pathname + '?' + params.toString();
}

export function ContentExplorerApplyFiltersControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const endpoint = authoritativeContentExplorerFilterEndpoint(context);
    const [search, setSearch] = useState('');
    const [type, setType] = useState<ExplorerFilters['type']>('all');
    const [status, setStatus] = useState<ExplorerFilters['status']>('all');
    const [perPage, setPerPage] = useState(10);
    const [applied, setApplied] = useState<ExplorerFilters | null>(null);
    const [revision, setRevision] = useState(0);

    const requestUrl = endpoint && applied ? contentExplorerFilterUrl(endpoint, applied) : null;
    const query = useQuery({
        queryKey: [
            'content-explorer-apply-filters',
            context.tenant.slug,
            (context as SiteAwareContext).active_site?.id ?? null,
            requestUrl,
            revision,
        ],
        queryFn: () => apiRequest<ExplorerEnvelope>(requestUrl as string),
        enabled: Boolean(requestUrl && revision > 0),
    });

    if (!endpoint) return null;

    const apply = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setApplied({ search, type, status, perPage });
        setRevision((value) => value + 1);
    };

    return (
        <section
            className="panel data-panel"
            aria-label={locale === 'ar' ? 'تصفية مستكشف المحتوى' : 'Content Explorer filters'}
        >
            <form className="toolbar-panel" onSubmit={apply}>
                <input
                    aria-label={locale === 'ar' ? 'عبارة البحث' : 'Search query'}
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder={locale === 'ar' ? 'ابحث في المحتوى' : 'Search content'}
                />
                <select
                    aria-label={locale === 'ar' ? 'نوع المحتوى' : 'Content type'}
                    value={type}
                    onChange={(event) => setType(event.target.value as ExplorerFilters['type'])}
                >
                    <option value="all">{locale === 'ar' ? 'الكل' : 'All'}</option>
                    <option value="post">{locale === 'ar' ? 'مقالات' : 'Posts'}</option>
                    <option value="page">{locale === 'ar' ? 'صفحات' : 'Pages'}</option>
                </select>
                <select
                    aria-label={locale === 'ar' ? 'حالة المحتوى' : 'Content status'}
                    value={status}
                    onChange={(event) => setStatus(event.target.value as ExplorerFilters['status'])}
                >
                    <option value="all">{locale === 'ar' ? 'كل الحالات' : 'All statuses'}</option>
                    <option value="publish">{locale === 'ar' ? 'منشور' : 'Published'}</option>
                    <option value="draft">{locale === 'ar' ? 'مسودة' : 'Draft'}</option>
                    <option value="pending">{locale === 'ar' ? 'قيد المراجعة' : 'Pending'}</option>
                    <option value="future">{locale === 'ar' ? 'مجدول' : 'Scheduled'}</option>
                </select>
                <select
                    aria-label={locale === 'ar' ? 'حجم الصفحة' : 'Page size'}
                    value={perPage}
                    onChange={(event) => setPerPage(Number(event.target.value))}
                >
                    <option value={10}>10</option>
                    <option value={25}>25</option>
                    <option value={50}>50</option>
                    <option value={100}>100</option>
                </select>
                <button
                    type="submit"
                    className="btn"
                    data-canonical-operation={CONTENT_EXPLORER_APPLY_FILTERS_OPERATION_ID}
                    disabled={query.isFetching}
                    aria-busy={query.isFetching ? 'true' : 'false'}
                >
                    {query.isFetching
                        ? (locale === 'ar' ? 'جاري البحث...' : 'Searching...')
                        : (locale === 'ar' ? 'بحث' : 'Search')}
                </button>
            </form>

            {query.error ? (
                <p role="alert">
                    {locale === 'ar' ? 'تعذر تحميل النتائج: ' : 'Results could not be loaded: '}
                    {query.error instanceof Error ? query.error.message : String(query.error)}
                </p>
            ) : null}

            {query.data ? (
                <div aria-live="polite">
                    <p>
                        {locale === 'ar'
                            ? String(query.data.total) + ' نتيجة'
                            : String(query.data.total) + ' results'}
                    </p>
                    {query.data.data.length === 0 ? (
                        <p>{locale === 'ar' ? 'لا توجد نتائج مطابقة.' : 'No matching content.'}</p>
                    ) : (
                        <div className="table-scroll">
                            <table className="data-table">
                                <thead>
                                    <tr>
                                        <th>{locale === 'ar' ? 'العنوان' : 'Title'}</th>
                                        <th>{locale === 'ar' ? 'النوع' : 'Type'}</th>
                                        <th>{locale === 'ar' ? 'الحالة' : 'Status'}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {query.data.data.map((row) => (
                                        <tr key={row.id}>
                                            <td>{row.title || row.slug || '#' + String(row.wordpress_id ?? row.id)}</td>
                                            <td>{row.type}</td>
                                            <td>{row.status}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            ) : null}
        </section>
    );
}
