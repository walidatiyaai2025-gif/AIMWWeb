import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SITES_DELETE_OPERATION_ID = 'AIMW-BILL-BE4B8C3822';

type SiteRow = { id: number; name?: string; url?: string; status?: string };

export function canonicalSitesDeleteCollection(context: FrontendContext): string | null {
    const endpoint = context.api.sites;
    const expected = `/api/tenants/${encodeURIComponent(context.tenant.slug)}/sites`;
    return endpoint === expected ? endpoint : null;
}

export function SitesDeleteControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const queryClient = useQueryClient();
    const [pendingSite, setPendingSite] = useState<SiteRow | null>(null);
    const [status, setStatus] = useState<{ tone: 'success' | 'error'; text: string } | null>(null);
    const allowed = context.permissions.includes('*') || context.permissions.includes('sites.manage');
    const endpoint = canonicalSitesDeleteCollection(context);

    const query = useQuery({
        queryKey: ['sites-delete-control', context.tenant.slug, endpoint],
        queryFn: () => apiRequest<SiteRow[]>(endpoint!),
        enabled: allowed && Boolean(endpoint),
    });
    const sites = useMemo(() => Array.isArray(query.data) ? query.data : [], [query.data]);

    const mutation = useMutation({
        mutationFn: async (site: SiteRow) => {
            if (!endpoint || !Number.isInteger(site.id) || site.id <= 0) throw new Error('Site deletion contract is unavailable.');
            await apiRequest<unknown>(`${endpoint}/${site.id}`, { method: 'DELETE' });
            const refreshed = await query.refetch();
            if (refreshed.error) throw refreshed.error;
            const rows = Array.isArray(refreshed.data) ? refreshed.data : [];
            if (rows.some((row) => row.id === site.id)) throw new Error('Site deletion was accepted but the authoritative Sites reread still contains the site.');
            return site;
        },
        onSuccess: async (site) => {
            await queryClient.invalidateQueries({ queryKey: ['workspace', context.tenant.slug, 'sites'] });
            setPendingSite(null);
            setStatus({
                tone: 'success',
                text: locale === 'ar' ? `تم حذف ${site.name ?? site.id} وتحديث قائمة المواقع من الخادم.` : `Deleted ${site.name ?? site.id} and reconciled the authoritative Sites list.`,
            });
        },
        onError: (error) => setStatus({
            tone: 'error',
            text: error instanceof Error ? error.message : (locale === 'ar' ? 'فشل حذف الموقع.' : 'Site deletion failed.'),
        }),
    });

    if (!allowed || !endpoint) return null;

    return (
        <section className="panel data-panel" aria-label={locale === 'ar' ? 'حذف موقع' : 'Delete site'}>
            <header className="panel-header">
                <div>
                    <span className="workspace-kicker">GOVERNED SITE ACTION</span>
                    <h2>{locale === 'ar' ? 'حذف ملف موقع' : 'Delete a site profile'}</h2>
                    <p>{locale === 'ar' ? 'الحذف يتطلب تأكيدًا، ثم يعيد تحميل القائمة الموثوقة قبل إعلان النجاح.' : 'Deletion requires confirmation and an authoritative Sites reread before success is shown.'}</p>
                </div>
            </header>
            {query.isLoading ? <p>{locale === 'ar' ? 'جارٍ تحميل المواقع…' : 'Loading sites…'}</p> : null}
            {query.error ? <p role="alert">{query.error instanceof ApiError ? query.error.message : String(query.error)}</p> : null}
            {status ? <p role={status.tone === 'error' ? 'alert' : 'status'}>{status.text}</p> : null}
            {sites.length ? (
                <div className="table-scroll" role="region" aria-label={locale === 'ar' ? 'المواقع القابلة للحذف' : 'Sites available for deletion'}>
                    <table className="data-table">
                        <thead><tr><th scope="col">ID</th><th scope="col">{locale === 'ar' ? 'الاسم' : 'Name'}</th><th scope="col">{locale === 'ar' ? 'الحالة' : 'Status'}</th><th scope="col">{locale === 'ar' ? 'إجراء' : 'Action'}</th></tr></thead>
                        <tbody>{sites.map((site) => (
                            <tr key={site.id}>
                                <td>{site.id}</td><td>{site.name ?? '—'}</td><td>{site.status ?? '—'}</td>
                                <td><button type="button" className="btn" onClick={() => { setStatus(null); setPendingSite(site); }} disabled={mutation.isPending}>{locale === 'ar' ? 'حذف' : 'Delete'}</button></td>
                            </tr>
                        ))}</tbody>
                    </table>
                </div>
            ) : (!query.isLoading && !query.error ? <p>{locale === 'ar' ? 'لا توجد مواقع قابلة للحذف.' : 'No sites are available for deletion.'}</p> : null)}

            {pendingSite ? (
                <div className="dialog-backdrop" role="presentation">
                    <div className="action-dialog" role="dialog" aria-modal="true" aria-labelledby="site-delete-title">
                        <h2 id="site-delete-title">{locale === 'ar' ? 'تأكيد حذف الموقع' : 'Confirm site deletion'}</h2>
                        <p>{locale === 'ar' ? `سيتم حذف ${pendingSite.name ?? pendingSite.id} من هذا الحساب.` : `Delete ${pendingSite.name ?? pendingSite.id} from this tenant?`}</p>
                        <div className="dialog-actions">
                            <button type="button" className="btn" onClick={() => setPendingSite(null)} disabled={mutation.isPending}>{locale === 'ar' ? 'إلغاء' : 'Cancel'}</button>
                            <button type="button" className="btn primary" data-canonical-operation={SITES_DELETE_OPERATION_ID} onClick={() => mutation.mutate(pendingSite)} disabled={mutation.isPending}>
                                {mutation.isPending ? (locale === 'ar' ? 'جارٍ الحذف…' : 'Deleting…') : (locale === 'ar' ? 'تأكيد الحذف' : 'Confirm delete')}
                            </button>
                        </div>
                    </div>
                </div>
            ) : null}
        </section>
    );
}
