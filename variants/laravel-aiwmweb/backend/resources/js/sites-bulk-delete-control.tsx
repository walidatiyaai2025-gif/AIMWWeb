import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiRequest, type FrontendContext } from './core';
import { useToast } from './components';
import { useLocale } from './i18n';
import { mutateThenReconcile } from './reconciliation';

const OPERATION_ID = 'AIMW-BILL-337E4FF969';
const REQUEST_OPERATION_ID = 'AIMW-SYNC-7C3B0E834E';
const TOGGLE_VISIBLE_OPERATION_ID = 'AIMW-BILL-F8102254A8';
const MAX_SITES = 100;

type SiteRow = { id: number; name?: string; url?: string; status?: string };

export function SitesBulkDeleteControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const { notify } = useToast();
    const queryClient = useQueryClient();
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [confirmOpen, setConfirmOpen] = useState(false);
    const endpoint = context.api.sites;
    const allowed = context.permissions.includes('*') || context.permissions.includes('sites.manage');

    const query = useQuery({
        queryKey: ['sites-bulk-delete', context.tenant.slug, endpoint],
        queryFn: () => apiRequest<SiteRow[]>(endpoint),
        enabled: allowed && Boolean(endpoint),
    });

    const sites = useMemo(() => Array.isArray(query.data) ? query.data : [], [query.data]);
    const visibleIds = useMemo(() => sites.slice(0, MAX_SITES).map((site) => site.id), [sites]);
    const selectedIds = useMemo(() => Array.from(selected).filter((id) => sites.some((site) => site.id === id)).slice(0, MAX_SITES), [selected, sites]);
    const allVisibleSelected = visibleIds.length > 0 && visibleIds.every((id) => selected.has(id));

    const mutation = useMutation({
        mutationFn: async (ids: number[]) => mutateThenReconcile(
            () => apiRequest<{ deleted: number; ids: number[] }>(endpoint, {
                method: 'DELETE',
                body: JSON.stringify({ ids }),
            }),
            async () => {
                const refreshed = await query.refetch();
                if (refreshed.error) throw refreshed.error;
            },
        ),
        onSuccess: async (result) => {
            await queryClient.invalidateQueries({ queryKey: ['workspace', context.tenant.slug, 'sites'] });
            setSelected(new Set());
            setConfirmOpen(false);
            notify(
                locale === 'ar' ? `تم حذف ${result.deleted} ملف موقع وتحديث القائمة من الخادم.` : `Deleted ${result.deleted} site profiles and reconciled the live list.`,
                'success',
            );
        },
        onError: (error) => notify(error instanceof Error ? error.message : (locale === 'ar' ? 'فشل حذف المواقع المحددة.' : 'Bulk site deletion failed.'), 'error'),
    });

    if (!allowed || !endpoint) return null;

    const toggle = (id: number) => {
        if (mutation.isPending) return;
        setSelected((current) => {
            const next = new Set(current);
            if (next.has(id)) next.delete(id);
            else if (next.size < MAX_SITES) next.add(id);
            return next;
        });
    };

    const toggleSelectAllVisible = () => {
        if (mutation.isPending) return;
        setSelected((current) => {
            const next = new Set(current);
            const clearVisible = visibleIds.length > 0 && visibleIds.every((id) => next.has(id));
            if (clearVisible) {
                visibleIds.forEach((id) => next.delete(id));
                return next;
            }
            for (const id of visibleIds) {
                if (next.size >= MAX_SITES) break;
                next.add(id);
            }
            return next;
        });
    };
    const requestBulkDelete = () => setConfirmOpen(true);

    return (
        <section className="panel data-panel" aria-label={locale === 'ar' ? 'الحذف الجماعي للمواقع' : 'Bulk site deletion'}>
            <header className="panel-header">
                <div>
                    <span className="workspace-kicker">GOVERNED BULK ACTION</span>
                    <h2>{locale === 'ar' ? 'حذف ملفات مواقع محددة' : 'Delete selected site profiles'}</h2>
                    <p>{locale === 'ar' ? 'اختر حتى 100 موقع. يتحقق الخادم من المجموعة كاملة قبل حذف أي عنصر.' : 'Select up to 100 sites. The server validates the complete set before deleting any profile.'}</p>
                </div>
                <span className="count-badge">{selectedIds.length}</span>
            </header>

            {query.isLoading ? <p>{locale === 'ar' ? 'جارٍ تحميل المواقع…' : 'Loading sites…'}</p> : null}
            {query.error ? <p role="alert">{query.error instanceof ApiError ? query.error.message : String(query.error)}</p> : null}

            {sites.length ? (
                <>
                    <div className="toolbar-actions">
                        <button
                            type="button"
                            className="btn"
                            data-canonical-operation={TOGGLE_VISIBLE_OPERATION_ID}
                            onClick={toggleSelectAllVisible}
                            disabled={mutation.isPending}
                        >{allVisibleSelected ? (locale === 'ar' ? 'إلغاء تحديد الظاهر' : 'Clear visible') : (locale === 'ar' ? 'تحديد الظاهر' : 'Select visible')}</button>
                        <button type="button" className="btn" onClick={() => setSelected(new Set())} disabled={mutation.isPending || selectedIds.length === 0}>{locale === 'ar' ? 'مسح التحديد' : 'Clear selection'}</button>
                        <button
                            type="button"
                            className="btn"
                            data-canonical-operation={REQUEST_OPERATION_ID}
                            onClick={requestBulkDelete}
                            disabled={mutation.isPending || selectedIds.length === 0}
                        >{locale === 'ar' ? 'حذف المحدد' : 'Delete selected'}</button>
                    </div>
                    <div className="table-scroll" role="region" aria-label={locale === 'ar' ? 'اختيار المواقع للحذف' : 'Select sites for deletion'}>
                        <table className="data-table">
                            <thead><tr><th scope="col">{locale === 'ar' ? 'تحديد' : 'Select'}</th><th scope="col">ID</th><th scope="col">{locale === 'ar' ? 'الاسم' : 'Name'}</th><th scope="col">{locale === 'ar' ? 'الحالة' : 'Status'}</th></tr></thead>
                            <tbody>{sites.map((site) => (
                                <tr key={site.id}>
                                    <td><input type="checkbox" checked={selected.has(site.id)} onChange={() => toggle(site.id)} disabled={mutation.isPending} aria-label={`${locale === 'ar' ? 'تحديد' : 'Select'} ${site.name ?? site.id}`} /></td>
                                    <td>{site.id}</td><td>{site.name ?? '—'}</td><td>{site.status ?? '—'}</td>
                                </tr>
                            ))}</tbody>
                        </table>
                    </div>
                </>
            ) : (!query.isLoading && !query.error ? <p>{locale === 'ar' ? 'لا توجد مواقع قابلة للتحديد.' : 'No sites are available for selection.'}</p> : null)}

            {confirmOpen ? (
                <div className="dialog-backdrop" role="presentation">
                    <div className="action-dialog" role="dialog" aria-modal="true" aria-labelledby="sites-bulk-delete-title">
                        <h2 id="sites-bulk-delete-title">{locale === 'ar' ? 'تأكيد حذف المواقع المحددة' : 'Confirm deletion of selected sites'}</h2>
                        <p>{locale === 'ar' ? `سيتم طلب حذف ${selectedIds.length} ملف موقع. لا يبدأ أي حذف إذا كانت المجموعة تحتوي موقعًا خارج الحساب أو عملية نشطة.` : `You are requesting deletion of ${selectedIds.length} site profiles. Nothing is deleted if the set contains a foreign site or an active execution.`}</p>
                        <div className="dialog-actions">
                            <button type="button" className="btn" onClick={() => setConfirmOpen(false)} disabled={mutation.isPending}>{locale === 'ar' ? 'إلغاء' : 'Cancel'}</button>
                            <button
                                type="button"
                                className="btn primary"
                                data-canonical-operation={OPERATION_ID}
                                onClick={() => mutation.mutate(selectedIds)}
                                disabled={mutation.isPending || selectedIds.length === 0}
                            >{mutation.isPending ? (locale === 'ar' ? 'جارٍ الحذف…' : 'Deleting…') : (locale === 'ar' ? 'تأكيد الحذف' : 'Confirm delete')}</button>
                        </div>
                    </div>
                </div>
            ) : null}
        </section>
    );
}
