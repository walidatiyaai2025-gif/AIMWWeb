import React, { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { apiRequest, type FrontendContext } from './core';
import { useToast } from './components';
import { useLocale } from './i18n';
import { mutateThenReconcile } from './reconciliation';

export const SITE_DETAILS_DELETE_OPERATION_ID = 'AIMW-BILL-BE4B8C3822';

type SiteSummary = { id?: number | string };

export function SiteDetailsDeleteControl({ context, siteId }: { context: FrontendContext; siteId: number | string }) {
    const { locale } = useLocale();
    const { notify } = useToast();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [confirming, setConfirming] = useState(false);

    const numericSiteId = Number(siteId);
    const tenantSlug = encodeURIComponent(context.tenant.slug);
    const expectedEndpoint = `/api/tenants/${tenantSlug}/sites/${numericSiteId}`;
    const expectedListEndpoint = `/api/tenants/${tenantSlug}/sites`;
    const advertisedEndpoint = context.api[`sites.detail.${numericSiteId}`];
    const advertisedListEndpoint = context.api.sites;
    const canManage = context.permissions.includes('*') || context.permissions.includes('sites.manage');

    const mutation = useMutation({
        mutationFn: async () => {
            let refreshedSites: SiteSummary[] = [];
            await mutateThenReconcile(
                () => apiRequest<void>(advertisedEndpoint, { method: 'DELETE' }),
                async () => {
                    const sites = await apiRequest<SiteSummary[]>(advertisedListEndpoint);
                    if (!Array.isArray(sites) || sites.some((candidate) => Number(candidate?.id) === numericSiteId)) {
                        throw new Error('Site deletion could not be authoritatively verified.');
                    }
                    refreshedSites = sites;
                },
            );
            return refreshedSites;
        },
        onSuccess: (sites) => {
            queryClient.setQueryData(['workspace', context.tenant.slug, 'sites'], sites);
            setConfirming(false);
            notify(locale === 'ar' ? 'تم حذف الموقع وتحديث القائمة من الخادم.' : 'Site deleted and reconciled from the server.', 'success');
            navigate(`/tenants/${tenantSlug}/sites`);
        },
        onError: (error) => notify(
            error instanceof Error ? error.message : (locale === 'ar' ? 'تعذر حذف الموقع.' : 'Unable to delete site.'),
            'error',
        ),
    });

    if (
        !canManage
        || !Number.isInteger(numericSiteId)
        || numericSiteId < 1
        || advertisedEndpoint !== expectedEndpoint
        || advertisedListEndpoint !== expectedListEndpoint
    ) return null;

    return (
        <section className="panel data-panel" aria-label={locale === 'ar' ? 'حذف الموقع' : 'Delete site'}>
            {!confirming ? (
                <button
                    type="button"
                    className="btn"
                    data-canonical-operation={SITE_DETAILS_DELETE_OPERATION_ID}
                    onClick={() => setConfirming(true)}
                    disabled={mutation.isPending}
                >{locale === 'ar' ? 'حذف الموقع' : 'Delete site'}</button>
            ) : (
                <div className="dialog-backdrop" role="presentation">
                    <div className="action-dialog" role="dialog" aria-modal="true" aria-labelledby="site-delete-title">
                        <h2 id="site-delete-title">{locale === 'ar' ? 'تأكيد حذف الموقع' : 'Confirm site deletion'}</h2>
                        <p>{locale === 'ar' ? 'سيتم حذف ملف الموقع من هذا الحساب فقط. يجب إنهاء أي تنفيذ منتظر أو جارٍ أولًا.' : 'This removes the site profile from this tenant only. Any queued or running execution must finish first.'}</p>
                        <div className="dialog-actions">
                            <button type="button" className="btn" onClick={() => setConfirming(false)} disabled={mutation.isPending}>{locale === 'ar' ? 'إلغاء' : 'Cancel'}</button>
                            <button
                                type="button"
                                className="btn primary"
                                data-canonical-operation={SITE_DETAILS_DELETE_OPERATION_ID}
                                onClick={() => mutation.mutate()}
                                disabled={mutation.isPending}
                            >{mutation.isPending ? (locale === 'ar' ? 'جارٍ الحذف…' : 'Deleting…') : (locale === 'ar' ? 'تأكيد الحذف' : 'Confirm delete')}</button>
                        </div>
                    </div>
                </div>
            )}
        </section>
    );
}
