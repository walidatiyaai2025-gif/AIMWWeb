import React, { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { ApiError, apiRequest, type FrontendContext } from './core';
import { StatePanel, useToast } from './components';
import { useLocale } from './i18n';
import { AuthoritativeReconciliationError, mutateThenReconcile } from './reconciliation';

export const MEDIA_DELETE_OPERATION_ID = 'AIMW-BILL-4DCB58743D';

export function MediaDeleteControl({
    context,
    endpoint,
    row,
    onReconcile,
}: {
    context: FrontendContext;
    endpoint: string;
    row: Record<string, unknown>;
    onReconcile: () => Promise<void>;
}) {
    const { locale } = useLocale();
    const { notify } = useToast();
    const [confirming, setConfirming] = useState(false);
    const mediaId = Number(row.id);
    const title = String(row.title ?? `#${row.remote_id ?? row.id ?? ''}`);
    const authorized = context.permissions.includes('*') || context.permissions.includes('content.edit');

    const mutation = useMutation({
        retry: false,
        mutationFn: () => mutateThenReconcile(
            () => apiRequest(`${endpoint}/${encodeURIComponent(String(mediaId))}`, { method: 'DELETE' }),
            onReconcile,
        ),
        onSuccess: () => {
            setConfirming(false);
            notify(
                locale === 'ar'
                    ? 'تم حذف الوسيط نهائيًا وتأكيد الحالة من WordPress.'
                    : 'Media was permanently deleted and reconciled from WordPress.',
                'success',
            );
        },
        onError: (error) => {
            if (error instanceof AuthoritativeReconciliationError) {
                notify(
                    locale === 'ar'
                        ? 'قَبِل الخادم الحذف، لكن تعذر تأكيد إعادة القراءة. أعد تحميل الشاشة قبل المحاولة مرة أخرى.'
                        : error.message,
                    'error',
                );
                return;
            }
            const message = error instanceof ApiError
                ? error.message
                : (error instanceof Error ? error.message : (locale === 'ar' ? 'فشل حذف الوسيط.' : 'Media deletion failed.'));
            notify(message, 'error');
        },
    });

    if (!authorized || !Number.isInteger(mediaId) || mediaId < 1) return null;

    return (
        <>
            <button
                type="button"
                className="btn danger"
                data-canonical-operation={MEDIA_DELETE_OPERATION_ID}
                disabled={mutation.isPending}
                onClick={() => setConfirming(true)}
            >
                {locale === 'ar' ? 'حذف نهائي' : 'Delete permanently'}
            </button>
            {confirming ? (
                <div className="dialog-backdrop" role="presentation">
                    <section
                        className="dialog"
                        role="alertdialog"
                        aria-modal="true"
                        aria-labelledby={`media-delete-title-${mediaId}`}
                        aria-describedby={`media-delete-description-${mediaId}`}
                        data-canonical-operation={MEDIA_DELETE_OPERATION_ID}
                    >
                        <header className="dialog-header">
                            <div>
                                <span className="workspace-kicker">DESTRUCTIVE ACTION</span>
                                <h2 id={`media-delete-title-${mediaId}`}>
                                    {locale === 'ar' ? 'حذف الوسيط نهائيًا؟' : 'Permanently delete media?'}
                                </h2>
                            </div>
                        </header>
                        <StatePanel tone="danger" title={title}>
                            <p id={`media-delete-description-${mediaId}`}>
                                {locale === 'ar'
                                    ? 'سيُستخدم force=true في WordPress. لا يمكن استعادة هذا الملف من سلة المهملات.'
                                    : 'WordPress force=true deletion will be used. This file cannot be restored from Trash.'}
                            </p>
                        </StatePanel>
                        <footer className="dialog-actions">
                            <button type="button" className="btn" disabled={mutation.isPending} onClick={() => setConfirming(false)}>
                                {locale === 'ar' ? 'إلغاء' : 'Cancel'}
                            </button>
                            <button
                                type="button"
                                className="btn danger"
                                data-canonical-operation={MEDIA_DELETE_OPERATION_ID}
                                disabled={mutation.isPending}
                                aria-busy={mutation.isPending}
                                onClick={() => mutation.mutate()}
                            >
                                {mutation.isPending
                                    ? (locale === 'ar' ? 'جارٍ الحذف…' : 'Deleting…')
                                    : (locale === 'ar' ? 'تأكيد الحذف النهائي' : 'Confirm permanent delete')}
                            </button>
                        </footer>
                    </section>
                </div>
            ) : null}
        </>
    );
}
