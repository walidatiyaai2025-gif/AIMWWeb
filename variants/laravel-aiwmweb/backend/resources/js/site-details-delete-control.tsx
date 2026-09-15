import React, { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { ApiError, apiRequest, tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';
import { useToast } from './components';

export const SITE_DETAILS_DELETE_OPERATION_ID = 'AIMW-BILL-BE4B8C3822';

type Props = {
    context: FrontendContext;
    siteId: string;
};

export function SiteDetailsDeleteControl({ context, siteId }: Props) {
    const { locale } = useLocale();
    const { notify } = useToast();
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [confirmOpen, setConfirmOpen] = useState(false);
    const allowed = context.permissions.includes('*') || context.permissions.includes('sites.manage');
    const numericSiteId = /^\d+$/.test(siteId) && Number(siteId) > 0 ? Number(siteId) : null;
    const detailEndpoint = numericSiteId === null ? undefined : context.api[`sites.detail.${numericSiteId}`];
    const expectedEndpoint = numericSiteId === null
        ? undefined
        : `/api/tenants/${encodeURIComponent(context.tenant.slug)}/sites/${numericSiteId}`;
    const endpoint = detailEndpoint === expectedEndpoint ? detailEndpoint : undefined;

    const mutation = useMutation({
        mutationFn: () => apiRequest<void>(endpoint!, { method: 'DELETE' }),
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['workspace', context.tenant.slug, 'sites'] });
            notify(locale === 'ar' ? 'تم حذف الموقع.' : 'Site deleted.', 'success');
            navigate(tenantUrl(context.tenant.slug, '/sites'), { replace: true });
        },
        onError: (error) => notify(
            error instanceof ApiError || error instanceof Error
                ? error.message
                : (locale === 'ar' ? 'تعذر حذف الموقع.' : 'Site deletion failed.'),
            'error',
        ),
    });

    if (!allowed || !endpoint) return null;

    return (
        <section className="panel data-panel" aria-label={locale === 'ar' ? 'حذف الموقع' : 'Delete site'}>
            <header className="panel-header">
                <div>
                    <span className="workspace-kicker">DESTRUCTIVE ACTION</span>
                    <h2>{locale === 'ar' ? 'حذف الموقع' : 'Delete site'}</h2>
                    <p>{locale === 'ar' ? 'يتطلب الحذف تأكيدًا صريحًا ولن ينجح إذا كان للموقع تنفيذ نشط.' : 'Deletion requires explicit confirmation and fails while the site has an active execution.'}</p>
                </div>
                <button type="button" className="btn" onClick={() => setConfirmOpen(true)} disabled={mutation.isPending}>
                    {locale === 'ar' ? 'حذف' : 'Delete'}
                </button>
            </header>

            {confirmOpen ? (
                <div className="dialog-backdrop" role="presentation">
                    <div className="action-dialog" role="dialog" aria-modal="true" aria-labelledby="site-delete-title">
                        <h2 id="site-delete-title">{locale === 'ar' ? 'تأكيد حذف الموقع' : 'Confirm site deletion'}</h2>
                        <p>{locale === 'ar' ? 'سيتم حذف ملف الموقع الحالي بعد تحقق الخادم من الحساب والموقع وحالة التنفيذ.' : 'The current site profile will be deleted only after the server revalidates tenant ownership, the site ID, and execution state.'}</p>
                        <div className="dialog-actions">
                            <button type="button" className="btn" onClick={() => setConfirmOpen(false)} disabled={mutation.isPending}>
                                {locale === 'ar' ? 'إلغاء' : 'Cancel'}
                            </button>
                            <button
                                type="button"
                                className="btn primary"
                                data-canonical-operation={SITE_DETAILS_DELETE_OPERATION_ID}
                                onClick={() => mutation.mutate()}
                                disabled={mutation.isPending}
                            >
                                {mutation.isPending ? (locale === 'ar' ? 'جارٍ الحذف…' : 'Deleting…') : (locale === 'ar' ? 'تأكيد الحذف' : 'Confirm delete')}
                            </button>
                        </div>
                    </div>
                </div>
            ) : null}
        </section>
    );
}
