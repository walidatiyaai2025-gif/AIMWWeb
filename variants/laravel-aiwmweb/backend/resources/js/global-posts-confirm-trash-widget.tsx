import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiRequest, type FrontendContext } from './core';
import { useToast } from './components';
import { useLocale } from './i18n';
import { GLOBAL_POSTS_RESET_OPERATION_ID, defaultGlobalPostsFilters, globalPostsFilterQuery } from './global-posts-reset-control';
import { GlobalPostsApplyFiltersControl, globalPostsFilterUrl, type GlobalPostsApplyFilterState } from './global-posts-apply-filters-control';
import { GLOBAL_POSTS_PRIVATE_SELECTED_OPERATION_ID, globalPostsPrivateEndpoint, globalPostsPrivatePayload } from './global-posts-private-selected-control';
import { GLOBAL_POSTS_DRAFT_SELECTED_OPERATION_ID, globalPostsDraftEndpoint, globalPostsDraftPayload } from './global-posts-draft-selected-control';
import { GLOBAL_POSTS_PENDING_SELECTED_OPERATION_ID, globalPostsPendingEndpoint, globalPostsPendingPayload } from './global-posts-pending-selected-control';
import { GLOBAL_POSTS_REQUEST_TRASH_OPERATION_ID, canRequestGlobalPostsTrash } from './global-posts-request-trash-control';
import { PostsInterpolatedLinksControl } from './posts-interpolated-links-control';
import { SyncAppConfirmDialogCancelControl } from './sync-app-confirm-dialog-cancel-control';
import {
    GLOBAL_POSTS_PUBLISH_SELECTED_OPERATION_ID,
    buildGlobalPostsPublishPayload,
    globalPostsPublishEndpoint,
} from './global-posts-publish-selected-control';
import {
    GLOBAL_POSTS_CONFIRM_TRASH_OPERATION_ID,
    buildGlobalPostsTrashPayload,
    globalPostsEndpoint,
    type GlobalPostTrashTarget,
} from './global-posts-confirm-trash-control';

type GlobalPageRow = {
    site_id: number;
    site_name: string;
    wordpress_id: number;
    title: string;
    status: string;
    modified_at?: string | null;
};

type GlobalPostsEnvelope = {
    data: GlobalPageRow[];
    total: number;
    current_page: number;
    last_page: number;
};

type PublishResult = {
    state: 'queued';
    count: number;
    site_jobs: number;
    jobs: Array<{ site_id: number; count: number; state: 'queued' }>;
};

type TrashResult = {
    succeeded: number;
    failed: number;
    total: number;
    message: string;
    results: Array<GlobalPostTrashTarget & {
        status: 'trashed' | 'already_trashed' | 'conflict' | 'failed';
    }>;
};

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

export function GlobalPostsConfirmTrashControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const { notify } = useToast();
    const queryClient = useQueryClient();
    const [filters, setFilters] = useState(defaultGlobalPostsFilters);
    const [filterDraft, setFilterDraft] = useState<GlobalPostsApplyFilterState>({ ...defaultGlobalPostsFilters(), siteId: '' });
    const [siteFilter, setSiteFilter] = useState('');
    const [filterRevision, setFilterRevision] = useState(0);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [confirmOpen, setConfirmOpen] = useState(false);
    const endpoint = globalPostsEndpoint(context.tenant.slug);
    const privateEndpoint = globalPostsPrivateEndpoint(context.tenant.slug);
    const pendingEndpoint = globalPostsPendingEndpoint(context.tenant.slug);
    const draftEndpoint = globalPostsDraftEndpoint(context.tenant.slug);
    const publishEndpoint = globalPostsPublishEndpoint(context.tenant.slug);
    const canView = hasPermission(context, 'content.view');
    const canEdit = hasPermission(context, 'content.edit');
    const enabled = Boolean(endpoint && canView);
    const sitesEndpoint = hasPermission(context, 'tenant.view')
        ? '/api/tenants/' + encodeURIComponent(context.tenant.slug) + '/sites'
        : null;
    const sitesQuery = useQuery({
        queryKey: ['global-posts-filter-sites', context.tenant.slug, sitesEndpoint],
        queryFn: () => apiRequest<Array<{ id: number; name: string }>>(sitesEndpoint as string),
        enabled: enabled && Boolean(sitesEndpoint),
    });

    const query = useQuery({
        queryKey: ['global-posts-confirm-trash', context.tenant.slug, filters.search, filters.status, filters.perPage, siteFilter, filterRevision],
        queryFn: () => apiRequest<GlobalPostsEnvelope>(`${endpoint}?${globalPostsFilterQuery(filters)}${siteFilter ? '&site_id=' + encodeURIComponent(siteFilter) : ''}`),
        enabled,
    });

    const rows = Array.isArray(query.data?.data) ? query.data.data : [];
    const selectedTargets = useMemo(
        () => rows
            .filter((row) => row.status !== 'trash' && selected.has(`${row.site_id}:${row.wordpress_id}`))
            .map((row) => ({ site_id: row.site_id, wordpress_id: row.wordpress_id } satisfies GlobalPostTrashTarget)),
        [rows, selected],
    );

    const publishMutation = useMutation({
        mutationFn: async (targets: GlobalPostTrashTarget[]) => {
            if (!publishEndpoint) throw new Error('Global posts publish endpoint is unavailable.');

            return apiRequest<PublishResult>(publishEndpoint, {
                method: 'POST',
                body: JSON.stringify(buildGlobalPostsPublishPayload(targets)),
            });
        },
        onSuccess: (result) => {
            setSelected(new Set());
            notify(
                locale === 'ar'
                    ? `تمت إضافة ${result.count} مقال إلى ${result.site_jobs} مهمة نشر حسب الموقع.`
                    : `Queued ${result.count} post(s) as ${result.site_jobs} site publish job(s).`,
                'success',
            );
        },
        onError: (error) => notify(
            error instanceof Error
                ? error.message
                : (locale === 'ar' ? 'تعذر إضافة المقالات المحددة إلى مهام النشر.' : 'Selected posts could not be queued for publishing.'),
            'error',
        ),
    });

    const privateMutation = useMutation({
        mutationFn: async (targets: Array<{ site_id: number; wordpress_id: number }>) => {
            if (!privateEndpoint) throw new Error('The requested private operation is unavailable.');
            return apiRequest<{ operation_id: string; state: 'queued'; count: number; site_jobs: number }>(
                privateEndpoint,
                { method: 'POST', body: JSON.stringify(globalPostsPrivatePayload(targets)) },
            );
        },
        onSuccess: (result) => {
            if (result.operation_id !== GLOBAL_POSTS_PRIVATE_SELECTED_OPERATION_ID || result.state !== 'queued') {
                notify('Unexpected operation response; refresh authoritative state before retrying.', 'error');
                return;
            }
            setSelected(new Set());
            notify(
                locale === 'ar'
                    ? `تمت جدولة ${result.count} من العناصر في ${result.site_jobs} مهمة. تحقق من النتائج بعد تنفيذها.`
                    : `Queued ${result.count} posts across ${result.site_jobs} site job(s). Verify authoritative state after execution.`,
                'success',
            );
        },
        onError: (error) => notify(error instanceof Error ? error.message : 'Could not queue selected items.', 'error'),
    });

    const draftMutation = useMutation({
        mutationFn: async (targets: Array<{ site_id: number; wordpress_id: number }>) => {
            if (!draftEndpoint) throw new Error('The requested draft operation is unavailable.');
            return apiRequest<{ operation_id: string; state: 'queued'; count: number; site_jobs: number }>(
                draftEndpoint,
                { method: 'POST', body: JSON.stringify(globalPostsDraftPayload(targets)) },
            );
        },
        onSuccess: (result) => {
            if (result.operation_id !== GLOBAL_POSTS_DRAFT_SELECTED_OPERATION_ID || result.state !== 'queued') {
                notify('Unexpected operation response; refresh authoritative state before retrying.', 'error');
                return;
            }
            setSelected(new Set());
            notify(
                locale === 'ar'
                    ? `تمت جدولة ${result.count} من العناصر في ${result.site_jobs} مهمة. تحقق من النتائج بعد تنفيذها.`
                    : `Queued ${result.count} posts across ${result.site_jobs} site job(s). Verify authoritative state after execution.`,
                'success',
            );
        },
        onError: (error) => notify(error instanceof Error ? error.message : 'Could not queue selected items.', 'error'),
    });

    const pendingMutation = useMutation({
        mutationFn: async (targets: Array<{ site_id: number; wordpress_id: number }>) => {
            if (!pendingEndpoint) throw new Error('The requested pending operation is unavailable.');
            return apiRequest<{ operation_id: string; state: 'queued'; count: number; site_jobs: number }>(
                pendingEndpoint,
                { method: 'POST', body: JSON.stringify(globalPostsPendingPayload(targets)) },
            );
        },
        onSuccess: (result) => {
            if (result.operation_id !== GLOBAL_POSTS_PENDING_SELECTED_OPERATION_ID || result.state !== 'queued') {
                notify('Unexpected operation response; refresh authoritative state before retrying.', 'error');
                return;
            }
            setSelected(new Set());
            notify(
                locale === 'ar'
                    ? `تمت جدولة ${result.count} من العناصر في ${result.site_jobs} مهمة. تحقق من النتائج بعد تنفيذها.`
                    : `Queued ${result.count} posts across ${result.site_jobs} site job(s). Verify authoritative state after execution.`,
                'success',
            );
        },
        onError: (error) => notify(error instanceof Error ? error.message : 'Could not queue selected items.', 'error'),
    });

    const mutation = useMutation({
        mutationFn: async (targets: GlobalPostTrashTarget[]) => {
            if (!endpoint) throw new Error('Global posts endpoint is unavailable.');

            return apiRequest<TrashResult>(`${endpoint}/trash`, {
                method: 'POST',
                body: JSON.stringify(buildGlobalPostsTrashPayload(targets)),
            });
        },
        onSuccess: async (result) => {
            const refreshed = await query.refetch();
            if (refreshed.error) {
                notify(
                    locale === 'ar'
                        ? 'تم تنفيذ الطلب لكن تعذر إعادة قراءة الحالة الموثوقة. أعد تحميل الصفحة قبل تكرار العملية.'
                        : 'The request completed but authoritative state could not be reread. Reload before retrying.',
                    'error',
                );
                return;
            }

            const failed = new Set(
                result.results
                    .filter((item) => item.status === 'failed' || item.status === 'conflict')
                    .map((item) => `${item.site_id}:${item.wordpress_id}`),
            );
            setSelected(failed);
            setConfirmOpen(false);
            notify(
                locale === 'ar'
                    ? `تم نقل ${result.succeeded} صفحة إلى سلة المهملات، وفشل ${result.failed}.`
                    : `Moved ${result.succeeded} post(s) to trash; ${result.failed} failed.`,
                result.succeeded === 0 ? 'error' : 'success',
            );
        },
        onError: (error) => notify(
            error instanceof Error
                ? error.message
                : (locale === 'ar' ? 'فشل نقل الصفحات إلى سلة المهملات.' : 'Moving pages to trash failed.'),
            'error',
        ),
    });

    if (!enabled) return null;

    const busy = mutation.isPending || publishMutation.isPending || privateMutation.isPending || pendingMutation.isPending || draftMutation.isPending;

    const resetFilters = () => {
        // ResetAsync: clear filters/selection and force a fresh authorized read, including at defaults.
        setSelected(new Set());
        setFilters(defaultGlobalPostsFilters());
        setFilterDraft({ ...defaultGlobalPostsFilters(), siteId: '' });
        setSiteFilter('');
        setFilterRevision((value) => value + 1);
        setConfirmOpen(false);
        void queryClient.invalidateQueries({ queryKey: ['global-posts-confirm-trash', context.tenant.slug] });
    };

    const toggle = (row: GlobalPageRow) => {
        if (busy || row.status === 'trash') return;
        const key = `${row.site_id}:${row.wordpress_id}`;
        setSelected((current) => {
            const next = new Set(current);
            if (next.has(key)) next.delete(key);
            else if (next.size < 100) next.add(key);
            return next;
        });
    };

    return (
        <section className="panel data-panel" aria-label={locale === 'ar' ? 'إدارة الصفحات عبر المواقع' : 'Global posts management'}>
            <header className="panel-header">
                <div>
                    <span className="workspace-kicker">GLOBAL PAGES</span>
                    <h2>{locale === 'ar' ? 'نقل الصفحات المحددة إلى سلة المهملات' : 'Move selected posts to trash'}</h2>
                    <p>{locale === 'ar'
                        ? 'يتم التحقق من كل موقع وصفحة ضمن الحساب الحالي قبل أي تغيير.'
                        : 'Every site and page is revalidated inside the active tenant before mutation.'}</p>
                </div>
                <span className="count-badge">{selectedTargets.length}</span>
            </header>

            {!canEdit ? (
                <p role="alert">{locale === 'ar' ? 'تحتاج صلاحية تعديل المحتوى لتنفيذ هذا الإجراء.' : 'Content edit permission is required for this action.'}</p>
            ) : null}
            <GlobalPostsApplyFiltersControl
                value={filterDraft}
                sites={Array.isArray(sitesQuery.data) ? sitesQuery.data : []}
                busy={busy || query.isFetching}
                siteSelectorAvailable={Boolean(sitesEndpoint) && !sitesQuery.isLoading && !sitesQuery.error}
                onChange={setFilterDraft}
                onApply={() => {
                    if (busy || query.isFetching || !globalPostsFilterUrl(context.tenant.slug, filterDraft)) return;
                    setSelected(new Set());
                    setConfirmOpen(false);
                    setFilters({ search: filterDraft.search, status: filterDraft.status, perPage: filterDraft.perPage });
                    setSiteFilter(filterDraft.siteId);
                    setFilterRevision((value) => value + 1);
                }}
            />
            <div className="toolbar-actions">
                <button type="button" className="btn" data-canonical-operation={GLOBAL_POSTS_RESET_OPERATION_ID}
                    disabled={busy || query.isFetching} onClick={resetFilters}>
                    {locale === 'ar' ? 'إعادة ضبط' : 'Reset'}
                </button>
            </div>
            {sitesQuery.error ? <p role="alert">{locale === 'ar' ? 'تعذر تحميل المواقع؛ استخدم البحث والحالة.' : 'Sites could not be loaded; search and status remain available.'}</p> : null}
            {query.isLoading ? <p>{locale === 'ar' ? 'جارٍ تحميل الصفحات…' : 'Loading pages…'}</p> : null}
            {query.error ? <p role="alert">{String(query.error)}</p> : null}

            {rows.length ? (
                <>
                    <div className="toolbar-actions">
                        <button
                            type="button"
                            className="btn"
                            data-canonical-operation={GLOBAL_POSTS_PRIVATE_SELECTED_OPERATION_ID}
                            disabled={!canEdit || !privateEndpoint || busy || selectedTargets.length === 0}
                            onClick={() => privateMutation.mutate(selectedTargets)}
                        >
                            {privateMutation.isPending
                                ? (locale === 'ar' ? 'جارٍ جدولة الحالة الخاصة…' : 'Queueing private status…')
                                : (locale === 'ar' ? 'جعل المحدد خاصًا' : 'Make selected private')}
                        </button>
                        <button
                            type="button"
                            className="btn"
                            data-canonical-operation={GLOBAL_POSTS_DRAFT_SELECTED_OPERATION_ID}
                            disabled={!canEdit || !draftEndpoint || busy || selectedTargets.length === 0}
                            onClick={() => draftMutation.mutate(selectedTargets)}
                        >
                            {draftMutation.isPending
                                ? (locale === 'ar' ? 'جارٍ جدولة المسودة…' : 'Queueing draft status…')
                                : (locale === 'ar' ? 'تحويل المحدد إلى مسودة' : 'Move selected to draft')}
                        </button>
                        <button
                            type="button"
                            className="btn"
                            data-canonical-operation={GLOBAL_POSTS_PENDING_SELECTED_OPERATION_ID}
                            disabled={!canEdit || !pendingEndpoint || busy || selectedTargets.length === 0}
                            onClick={() => pendingMutation.mutate(selectedTargets)}
                        >
                            {pendingMutation.isPending
                                ? (locale === 'ar' ? 'جارٍ جدولة المراجعة…' : 'Queueing review status…')
                                : (locale === 'ar' ? 'إرسال المحدد للمراجعة' : 'Send selected to review')}
                        </button>
                        <button
                            type="button"
                            className="btn primary"
                            data-canonical-operation={GLOBAL_POSTS_PUBLISH_SELECTED_OPERATION_ID}
                            disabled={!canEdit || busy || selectedTargets.length === 0}
                            onClick={() => publishMutation.mutate(selectedTargets)}
                        >
                            {publishMutation.isPending
                                ? (locale === 'ar' ? 'جارٍ إضافة مهام النشر…' : 'Queueing publish jobs…')
                                : (locale === 'ar' ? 'نشر المحدد' : 'Publish selected')}
                        </button>
                        <button
                            type="button"
                            className="btn danger"
                            data-canonical-operation={GLOBAL_POSTS_CONFIRM_TRASH_OPERATION_ID}
                            data-request-operation={GLOBAL_POSTS_REQUEST_TRASH_OPERATION_ID}
                            disabled={!canRequestGlobalPostsTrash(selectedTargets.length, canEdit, busy)}
                            onClick={() => {
                                if (canRequestGlobalPostsTrash(selectedTargets.length, canEdit, busy)) setConfirmOpen(true);
                            }}
                        >
                            {locale === 'ar' ? 'نقل المحدد لسلة المهملات' : 'Move selected to trash'}
                        </button>
                        <button
                            type="button"
                            className="btn"
                            disabled={busy || selectedTargets.length === 0}
                            onClick={() => setSelected(new Set())}
                        >
                            {locale === 'ar' ? 'مسح التحديد' : 'Clear selection'}
                        </button>
                    </div>

                    <div className="table-scroll" role="region" aria-label={locale === 'ar' ? 'اختيار الصفحات' : 'Post selection'}>
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>{locale === 'ar' ? 'تحديد' : 'Select'}</th>
                                    <th>{locale === 'ar' ? 'العنوان' : 'Title'}</th>
                                    <th>{locale === 'ar' ? 'الموقع' : 'Site'}</th>
                                    <th>{locale === 'ar' ? 'الحالة' : 'Status'}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => {
                                    const key = `${row.site_id}:${row.wordpress_id}`;
                                    return (
                                        <tr key={key}>
                                            <td>
                                                <input
                                                    type="checkbox"
                                                    checked={selected.has(key)}
                                                    disabled={busy || row.status === 'trash'}
                                                    onChange={() => toggle(row)}
                                                    aria-label={`${locale === 'ar' ? 'تحديد' : 'Select'} ${row.title || key}`}
                                                />
                                            </td>
                                            <td>{row.title || `#${row.wordpress_id}`}</td>
                                            <td>
                                                <PostsInterpolatedLinksControl
                                                    tenantSlug={context.tenant.slug}
                                                    siteId={row.site_id}
                                                    wordpressId={row.wordpress_id}
                                                    siteName={row.site_name}
                                                />
                                            </td>
                                            <td>{row.status || '—'}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </>
            ) : (!query.isLoading ? <p>{locale === 'ar' ? 'لا توجد صفحات متزامنة.' : 'No synchronized pages are available.'}</p> : null)}

            {confirmOpen ? (
                <div className="dialog-backdrop" role="presentation">
                    <section className="dialog-card" role="dialog" aria-modal="true" aria-labelledby="global-posts-trash-title">
                        <h3 id="global-posts-trash-title">{locale === 'ar' ? 'نقل الصفحات إلى سلة المهملات؟' : 'Move pages to trash?'}</h3>
                        <p>{locale === 'ar'
                            ? `سيتم تغيير ${selectedTargets.length} صفحة محددة عبر مواقع WordPress الحية.`
                            : `This will change ${selectedTargets.length} selected post(s) across live WordPress sites.`}</p>
                        <div className="toolbar-actions">
                            <button
                                type="button"
                                className="btn danger"
                                disabled={mutation.isPending || selectedTargets.length === 0}
                                onClick={() => mutation.mutate(selectedTargets)}
                            >
                                {mutation.isPending
                                    ? (locale === 'ar' ? 'جارٍ النقل…' : 'Moving…')
                                    : (locale === 'ar' ? 'تأكيد النقل' : 'Confirm trash')}
                            </button>
                            <SyncAppConfirmDialogCancelControl
                                busy={mutation.isPending}
                                cancelText={locale === 'ar' ? 'إلغاء' : 'Cancel'}
                                onOpenChanged={(open) => setConfirmOpen(open)}
                                onCancel={() => undefined}
                            />
                        </div>
                    </section>
                </div>
            ) : null}
        </section>
    );
}
