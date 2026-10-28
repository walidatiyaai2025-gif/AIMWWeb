import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiError, apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SITES_ENABLE_SELECTED_OPERATION_ID = 'AIMW-SYNC-38D8A24B15';
const MAX_SELECTED_SITES = 100;

type SiteRow = { id: number; name: string; connection_status?: string };
type Result = { requested: number; updated: number; connection_status: string; ids: number[] };

export function SitesBulkOperationalStateControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const queryClient = useQueryClient();
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [message, setMessage] = useState<{ role: 'status' | 'alert'; text: string } | null>(null);
    const collection = `/api/tenants/${encodeURIComponent(context.tenant.slug)}/sites`;
    const authorized = (context.permissions.includes('*') || context.permissions.includes('sites.manage'))
        && (context.permissions.includes('*') || context.permissions.includes('sites.view'))
        && context.api.sites === collection;

    const query = useQuery({
        queryKey: ['sites-bulk-operational-state', context.tenant.slug],
        queryFn: () => apiRequest<SiteRow[]>(collection),
        enabled: authorized,
        retry: false,
    });
    const sites = useMemo(() => Array.isArray(query.data) ? query.data : [], [query.data]);
    const selectedIds = Array.from(selected).filter(id => sites.some(site => site.id === id)).slice(0, MAX_SELECTED_SITES);

    const enable = useMutation({
        mutationFn: async (ids: number[]) => {
            if (!authorized || !ids.length) throw new Error('No authorized sites are selected.');
            const result = await apiRequest<Result>(`${collection}/bulk-enable`, {
                method: 'POST', body: JSON.stringify({ ids }),
            });
            const refreshed = await query.refetch();
            if (refreshed.error || !Array.isArray(refreshed.data)) {
                throw new Error('The selected sites were changed but the authoritative reread failed.');
            }
            if (!ids.every(id => refreshed.data!.some(site => site.id === id && site.connection_status === 'unknown'))) {
                throw new Error('The authoritative Sites collection does not confirm the enabled state.');
            }
            return result;
        },
        onSuccess: async (result) => {
            await queryClient.invalidateQueries({ queryKey: ['workspace', context.tenant.slug, 'sites'] });
            setSelected(new Set());
            setMessage({ role: 'status', text: locale === 'ar'
                ? `تم تفعيل ${result.requested} موقع وإعادتها لانتظار اختبار الاتصال.`
                : `Enabled ${result.requested} sites and reset them for connection testing.` });
        },
        onError: error => setMessage({ role: 'alert', text: error instanceof ApiError || error instanceof Error
            ? error.message : 'Selected-site enable failed.' }),
    });

    if (!authorized) return null;

    const toggle = (id: number) => {
        if (enable.isPending) return;
        setSelected(current => {
            const next = new Set(current);
            if (next.has(id)) next.delete(id);
            else if (next.size < MAX_SELECTED_SITES) next.add(id);
            return next;
        });
    };

    return (
        <section className="panel data-panel" aria-label={locale === 'ar' ? 'حالة المواقع المحددة' : 'Selected site operational state'}>
            <header className="panel-header">
                <div><span className="workspace-kicker">TENANT SITE STATE</span>
                    <h2>{locale === 'ar' ? 'حالة المواقع المحددة' : 'Selected site operational state'}</h2>
                    <p>{locale === 'ar' ? 'غيّر حالة ملفات اتصال المواقع المحددة ثم تحقّق من النتيجة المخزنة.'
                        : 'Update selected tenant site profiles and verify the authoritative stored result.'}</p>
                </div><span className="count-badge">{selectedIds.length}</span>
            </header>
            {message ? <p role={message.role}>{message.text}</p> : null}
            {query.isLoading ? <p>{locale === 'ar' ? 'جارٍ تحميل المواقع…' : 'Loading sites…'}</p> : null}
            {query.error ? <p role="alert">{query.error instanceof Error ? query.error.message : String(query.error)}</p> : null}
            {sites.length ? <>
                <div className="toolbar-actions">
                    <button type="button" className="btn" data-canonical-operation={SITES_ENABLE_SELECTED_OPERATION_ID}
                        disabled={enable.isPending || selectedIds.length === 0}
                        aria-busy={enable.isPending ? 'true' : 'false'}
                        onClick={() => { setMessage(null); enable.mutate(selectedIds); }}>
                        {enable.isPending ? (locale === 'ar' ? 'جارٍ التفعيل…' : 'Enabling…') : (locale === 'ar' ? 'تفعيل المحدد' : 'Enable selected')}
                    </button>
                    <button type="button" className="btn" disabled={enable.isPending || selectedIds.length === 0}
                        onClick={() => setSelected(new Set())}>{locale === 'ar' ? 'إلغاء التحديد' : 'Clear selection'}</button>
                </div>
                <div className="table-scroll" role="region" aria-label="Select sites for operational state change">
                    <table className="data-table">
                        <thead><tr><th>{locale === 'ar' ? 'تحديد' : 'Select'}</th><th>ID</th><th>{locale === 'ar' ? 'الاسم' : 'Name'}</th><th>{locale === 'ar' ? 'حالة الاتصال' : 'Connection'}</th></tr></thead>
                        <tbody>{sites.map(site => <tr key={site.id}>
                            <td><input type="checkbox" aria-label={`Select ${site.name}`} checked={selected.has(site.id)}
                                disabled={enable.isPending} onChange={() => toggle(site.id)} /></td>
                            <td>{site.id}</td><td>{site.name}</td><td>{site.connection_status ?? 'unknown'}</td>
                        </tr>)}</tbody>
                    </table>
                </div>
            </> : (!query.isLoading && !query.error ? <p>{locale === 'ar' ? 'لا توجد مواقع.' : 'No sites available.'}</p> : null)}
        </section>
    );
}
