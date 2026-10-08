import React, { useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { apiRequest, type FrontendContext } from './core';
import { useToast } from './components';
import { useLocale } from './i18n';
import {
    CONTENT_EXPLORER_BULK_TRASH_OPERATION_ID,
    buildBulkTrashPayload,
    contentExplorerBulkTrashEndpoint,
    type BulkTrashTarget,
} from './content-explorer-bulk-trash-control';

type ActiveSite = {
    id: number;
    name: string;
    status?: string | null;
};

type ContextWithActiveSite = FrontendContext & {
    active_site?: ActiveSite | null;
};

type ContentRow = {
    id: number;
    remote_id: number;
    type: 'post' | 'page';
    title?: string | null;
    status?: string | null;
};

type ContentPage = {
    data?: ContentRow[];
};

type BulkTrashResult = {
    succeeded: number;
    failed: number;
    total: number;
    message: string;
    results: Array<BulkTrashTarget & {
        status: 'trashed' | 'conflict' | 'failed';
        conflict_id?: number;
    }>;
};

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

function pageRows(page: ContentPage | undefined, type: 'post' | 'page'): ContentRow[] {
    if (!Array.isArray(page?.data)) return [];
    return page.data
        .filter((row) => Number.isSafeInteger(Number(row.remote_id)) && Number(row.remote_id) > 0)
        .map((row) => ({ ...row, type, remote_id: Number(row.remote_id) }));
}

export function ContentExplorerBulkTrashControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const { notify } = useToast();
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const activeSite = (context as ContextWithActiveSite).active_site;
    const siteId = activeSite?.id ?? 0;
    const canView = hasPermission(context, 'content.view');
    const canEdit = hasPermission(context, 'content.edit');
    const endpoint = contentExplorerBulkTrashEndpoint(context.tenant.slug, siteId);
    const postsEndpoint = context.api.posts;
    const pagesEndpoint = context.api.pages;
    const enabled = Boolean(endpoint && postsEndpoint && pagesEndpoint && canView && canEdit);

    const postsQuery = useQuery({
        queryKey: ['content-explorer-bulk-trash', context.tenant.slug, siteId, 'post'],
        queryFn: () => apiRequest<ContentPage>(`${postsEndpoint}?per_page=100`),
        enabled,
    });
    const pagesQuery = useQuery({
        queryKey: ['content-explorer-bulk-trash', context.tenant.slug, siteId, 'page'],
        queryFn: () => apiRequest<ContentPage>(`${pagesEndpoint}?per_page=100`),
        enabled,
    });

    const rows = useMemo(
        () => [...pageRows(postsQuery.data, 'post'), ...pageRows(pagesQuery.data, 'page')],
        [postsQuery.data, pagesQuery.data],
    );
    const selectedTargets = useMemo(
        () => rows
            .filter((row) => selected.has(`${row.type}:${row.remote_id}`))
            .map((row) => ({ content_type: row.type, wordpress_id: row.remote_id } satisfies BulkTrashTarget)),
        [rows, selected],
    );

    const mutation = useMutation({
        mutationFn: async (targets: BulkTrashTarget[]) => {
            if (!endpoint) throw new Error('Bulk trash endpoint is unavailable.');
            return apiRequest<BulkTrashResult>(endpoint, {
                method: 'POST',
                body: JSON.stringify(buildBulkTrashPayload(targets)),
            });
        },
        onSuccess: async (result) => {
            await Promise.all([postsQuery.refetch(), pagesQuery.refetch()]);
            const failedKeys = new Set(
                result.results
                    .filter((item) => item.status !== 'trashed')
                    .map((item) => `${item.content_type}:${item.wordpress_id}`),
            );
            setSelected(failedKeys);
            notify(
                locale === 'ar'
                    ? `تم نقل ${result.succeeded} عنصر إلى سلة المهملات، وفشل ${result.failed}.`
                    : `Moved ${result.succeeded} item(s) to trash; ${result.failed} failed.`,
                result.failed === result.total ? 'error' : 'success',
            );
        },
        onError: (error) => notify(
            error instanceof Error
                ? error.message
                : (locale === 'ar' ? 'فشل نقل المحتوى المحدد إلى سلة المهملات.' : 'Bulk trash failed.'),
            'error',
        ),
    });

    if (!enabled || !activeSite) return null;

    const toggle = (row: ContentRow) => {
        if (mutation.isPending) return;
        const key = `${row.type}:${row.remote_id}`;
        setSelected((current) => {
            const next = new Set(current);
            if (next.has(key)) next.delete(key);
            else if (next.size < 500) next.add(key);
            return next;
        });
    };

    return (
        <section className="panel data-panel" aria-label={locale === 'ar' ? 'نقل المحتوى المحدد إلى سلة المهملات' : 'Bulk trash selected content'}>
            <header className="panel-header">
                <div>
                    <span className="workspace-kicker">CONTENT OPERATIONS</span>
                    <h2>{locale === 'ar' ? 'نقل المحدد إلى سلة المهملات' : 'Move selected content to trash'}</h2>
                    <p>{locale === 'ar'
                        ? `الموقع: ${activeSite.name}. يتم التحقق من كل عنصر على الخادم قبل التغيير.`
                        : `Site: ${activeSite.name}. Each item is verified by the server before mutation.`}</p>
                </div>
                <span className="count-badge">{selectedTargets.length}</span>
            </header>

            {postsQuery.isLoading || pagesQuery.isLoading
                ? <p>{locale === 'ar' ? 'جارٍ تحميل المحتوى…' : 'Loading content…'}</p>
                : null}
            {postsQuery.error || pagesQuery.error
                ? <p role="alert">{String(postsQuery.error ?? pagesQuery.error)}</p>
                : null}

            {rows.length ? (
                <>
                    <div className="toolbar-actions">
                        <button
                            type="button"
                            className="btn danger"
                            data-canonical-operation={CONTENT_EXPLORER_BULK_TRASH_OPERATION_ID}
                            onClick={() => mutation.mutate(selectedTargets)}
                            disabled={mutation.isPending || selectedTargets.length === 0}
                        >
                            {mutation.isPending
                                ? (locale === 'ar' ? 'جارٍ النقل…' : 'Moving…')
                                : (locale === 'ar' ? 'نقل المحدد لسلة المهملات' : 'Move selected to trash')}
                        </button>
                        <button
                            type="button"
                            className="btn"
                            onClick={() => setSelected(new Set())}
                            disabled={mutation.isPending || selectedTargets.length === 0}
                        >
                            {locale === 'ar' ? 'مسح التحديد' : 'Clear selection'}
                        </button>
                    </div>
                    <div className="table-scroll" role="region" aria-label={locale === 'ar' ? 'اختيار المحتوى' : 'Content selection'}>
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th scope="col">{locale === 'ar' ? 'تحديد' : 'Select'}</th>
                                    <th scope="col">{locale === 'ar' ? 'العنوان' : 'Title'}</th>
                                    <th scope="col">{locale === 'ar' ? 'النوع' : 'Type'}</th>
                                    <th scope="col">{locale === 'ar' ? 'الحالة' : 'Status'}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => {
                                    const key = `${row.type}:${row.remote_id}`;
                                    return (
                                        <tr key={key}>
                                            <td>
                                                <input
                                                    type="checkbox"
                                                    checked={selected.has(key)}
                                                    onChange={() => toggle(row)}
                                                    disabled={mutation.isPending}
                                                    aria-label={`${locale === 'ar' ? 'تحديد' : 'Select'} ${row.title ?? key}`}
                                                />
                                            </td>
                                            <td>{row.title ?? `#${row.remote_id}`}</td>
                                            <td>{row.type}</td>
                                            <td>{row.status ?? '—'}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </>
            ) : (!postsQuery.isLoading && !pagesQuery.isLoading
                ? <p>{locale === 'ar' ? 'لا يوجد محتوى متاح للتحديد.' : 'No content is available for selection.'}</p>
                : null)}
        </section>
    );
}
