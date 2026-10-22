import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SITES_RETEST_SELECTED_OPERATION_ID = 'AIMW-BILL-DD0FD9926B';
export const SITES_RETEST_SELECTED_MAX = 100;

type SiteRow = { id: number; name?: string; status?: string };
type RecheckPayload = { id?: number; classification?: string | null; message?: string | null };

export function canonicalSitesRetestCollection(context: FrontendContext): string | null {
    const expected = `/api/tenants/${encodeURIComponent(context.tenant.slug)}/sites`;
    return context.api.sites === expected ? expected : null;
}

export function canonicalSitesRetestEndpoint(collection: string, siteId: number): string | null {
    if (!/^\/api\/tenants\/[^/?#]+\/sites$/.test(collection) || !Number.isInteger(siteId) || siteId < 1) return null;
    return `${collection}/${siteId}/connection/recheck`;
}

export function SitesRetestSelectedControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const queryClient = useQueryClient();
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [message, setMessage] = useState<{ role: 'status' | 'alert'; text: string } | null>(null);
    const collection = canonicalSitesRetestCollection(context);
    const allowed = context.permissions.includes('*') || context.permissions.includes('connector.manage');

    const query = useQuery({
        queryKey: ['sites-retest-selected', context.tenant.slug, collection],
        queryFn: () => apiRequest<SiteRow[]>(collection!),
        enabled: allowed && Boolean(collection),
        retry: false,
    });

    const sites = useMemo(() => Array.isArray(query.data) ? query.data : [], [query.data]);
    const selectedIds = useMemo(
        () => Array.from(selected).filter((id) => sites.some((site) => site.id === id)).slice(0, SITES_RETEST_SELECTED_MAX),
        [selected, sites],
    );

    const mutation = useMutation({
        mutationFn: async (ids: number[]) => {
            if (!collection || ids.length === 0) throw new Error('No tenant-scoped sites are selected for retest.');
            const results = await Promise.all(ids.map(async (id) => {
                const endpoint = canonicalSitesRetestEndpoint(collection, id);
                if (!endpoint) return { id, ok: false as const };
                try {
                    await apiRequest<RecheckPayload>(endpoint, { method: 'POST', body: JSON.stringify({}) });
                    return { id, ok: true as const };
                } catch {
                    return { id, ok: false as const };
                }
            }));

            const refreshed = await query.refetch();
            if (refreshed.error) throw refreshed.error;
            if (!Array.isArray(refreshed.data)) throw new Error('Retest completed but authoritative Sites state could not be reread.');

            return {
                requested: ids.length,
                succeeded: results.filter((item) => item.ok).length,
                failed: results.filter((item) => !item.ok).length,
            };
        },
        onSuccess: async (result) => {
            await queryClient.invalidateQueries({ queryKey: ['workspace', context.tenant.slug, 'sites'] });
            setSelected(new Set());
            const text = locale === 'ar'
                ? `اكتمل اختبار ${result.requested} موقع: ${result.succeeded} ناجح، ${result.failed} يحتاج مراجعة.`
                : `Retested ${result.requested} sites: ${result.succeeded} succeeded, ${result.failed} need review.`;
            setMessage({ role: result.failed === 0 ? 'status' : 'alert', text });
        },
        onError: (error) => setMessage({
            role: 'alert',
            text: error instanceof ApiError || error instanceof Error ? error.message : 'Selected-site retest failed.',
        }),
    });

    if (!allowed || !collection) return null;

    const toggle = (id: number) => {
        if (mutation.isPending) return;
        setSelected((current) => {
            const next = new Set(current);
            if (next.has(id)) next.delete(id);
            else if (next.size < SITES_RETEST_SELECTED_MAX) next.add(id);
            return next;
        });
    };

    return (
        <section className="panel data-panel" aria-label={locale === 'ar' ? 'إعادة اختبار المواقع المحددة' : 'Retest selected sites'}>
            <header className="panel-header">
                <div>
                    <span className="workspace-kicker">TENANT CONNECTION RETEST</span>
                    <h2>{locale === 'ar' ? 'إعادة اختبار المواقع المحددة' : 'Retest selected sites'}</h2>
                    <p>{locale === 'ar'
                        ? 'يتم تشغيل اختبار الاتصال الحقيقي لكل موقع محدد ثم إعادة قراءة قائمة المواقع من الخادم.'
                        : 'Runs the real connection recheck for each selected tenant site, then rereads the authoritative Sites collection.'}</p>
                </div>
                <span className="count-badge">{selectedIds.length}</span>
            </header>
            {message ? <p role={message.role}>{message.text}</p> : null}
            {query.isLoading ? <p>{locale === 'ar' ? 'جارٍ تحميل المواقع…' : 'Loading sites…'}</p> : null}
            {query.error ? <p role="alert">{query.error instanceof Error ? query.error.message : String(query.error)}</p> : null}
            {sites.length ? (
                <>
                    <div className="toolbar-actions">
                        <button type="button" className="btn" data-canonical-operation={SITES_RETEST_SELECTED_OPERATION_ID}
                            disabled={mutation.isPending || selectedIds.length === 0}
                            aria-busy={mutation.isPending ? 'true' : 'false'}
                            onClick={() => { setMessage(null); mutation.mutate(selectedIds); }}>
                            {mutation.isPending ? (locale === 'ar' ? 'جارٍ إعادة الاختبار…' : 'Retesting…') : (locale === 'ar' ? 'إعادة اختبار المحدد' : 'Retest selected')}
                        </button>
                        <button type="button" className="btn" disabled={mutation.isPending || selectedIds.length === 0}
                            onClick={() => setSelected(new Set())}>{locale === 'ar' ? 'إلغاء التحديد' : 'Clear selection'}</button>
                    </div>
                    <div className="table-scroll" role="region" aria-label={locale === 'ar' ? 'اختيار مواقع لإعادة الاختبار' : 'Select sites to retest'}>
                        <table className="data-table">
                            <thead><tr><th>{locale === 'ar' ? 'تحديد' : 'Select'}</th><th>ID</th><th>{locale === 'ar' ? 'الاسم' : 'Name'}</th><th>{locale === 'ar' ? 'الحالة' : 'Status'}</th></tr></thead>
                            <tbody>{sites.map((site) => (
                                <tr key={site.id}>
                                    <td><input aria-label={`${locale === 'ar' ? 'تحديد' : 'Select'} ${site.name ?? site.id}`} type="checkbox"
                                        checked={selected.has(site.id)} disabled={mutation.isPending} onChange={() => toggle(site.id)} /></td>
                                    <td>{site.id}</td><td>{site.name ?? '—'}</td><td>{site.status ?? '—'}</td>
                                </tr>
                            ))}</tbody>
                        </table>
                    </div>
                </>
            ) : (!query.isLoading && !query.error ? <p>{locale === 'ar' ? 'لا توجد مواقع لإعادة اختبارها.' : 'No sites are available to retest.'}</p> : null)}
        </section>
    );
}
