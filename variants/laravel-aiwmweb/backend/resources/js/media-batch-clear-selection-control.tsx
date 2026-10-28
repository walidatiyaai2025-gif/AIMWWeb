import React, { useRef, useState } from 'react';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const MEDIA_BATCH_CLEAR_SELECTION_OPERATION_ID = 'AIMW-SYNC-B83AB5A666';
export const MEDIA_BATCH_MAX_FILES = 20;

type ActiveSite = { id: number };
type MediaContext = FrontendContext & { active_site?: ActiveSite | null };

export function authorizedMediaUploadEndpoint(context: FrontendContext): string | null {
    const siteId = (context as MediaContext).active_site?.id;
    if (!context.tenant.slug || !Number.isSafeInteger(siteId) || !siteId || siteId < 1) return null;
    if (!(context.permissions.includes('*') || (
        context.permissions.includes('tenant.view')
        && context.permissions.includes('sites.view')
        && context.permissions.includes('content.view')
        && context.permissions.includes('content.edit')
    ))) return null;

    const expected = `/api/v1/tenants/${encodeURIComponent(context.tenant.slug)}/sites/${siteId}/media`;
    return context.api.media === expected ? expected : null;
}

/**
 * The source MediaBatchUploadPanel ClearClickedAsync is a purely local cancellation.
 * Only the existing site-owned media upload API may enqueue uploads; cancel must never
 * issue a network request or try to cancel a transfer already accepted by the server.
 */
export function MediaBatchClearSelectionControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const fileInput = useRef<HTMLInputElement>(null);
    const [files, setFiles] = useState<File[]>([]);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const endpoint = authorizedMediaUploadEndpoint(context);

    if (!endpoint) return null;

    const clearSelection = () => {
        if (busy) return;
        setFiles([]);
        setMessage('');
        if (fileInput.current) fileInput.current.value = '';
    };

    const enqueue = async () => {
        if (busy || files.length < 1 || files.length > MEDIA_BATCH_MAX_FILES) return;
        setBusy(true);
        setMessage('');
        let accepted = 0;
        try {
            for (const file of files) {
                const payload = new FormData();
                payload.append('file', file, file.name);
                // The existing endpoint durably queues each upload; HTTP 202 is not
                // proof that WordPress already contains the media item.
                await apiRequest(endpoint, { method: 'POST', body: payload });
                accepted++;
            }
            setFiles([]);
            if (fileInput.current) fileInput.current.value = '';
            setMessage(locale === 'ar'
                ? `تم إدراج ${accepted} ملف في قائمة الرفع. راجع حالة المهام لتأكيد النتيجة.`
                : `${accepted} file(s) queued. Check execution status for the remote result.`);
        } catch (error) {
            // Retain only files not acknowledged by Laravel to avoid accidental
            // duplicate enqueue on retry after a partially accepted batch.
            setFiles(files.slice(accepted));
            if (fileInput.current) fileInput.current.value = '';
            setMessage(error instanceof Error ? error.message : (locale === 'ar' ? 'تعذّر إدراج الملفات.' : 'Files could not be queued.'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <section className="toolbar-panel" aria-label={locale === 'ar' ? 'الرفع الجماعي للوسائط' : 'Batch media upload'}>
            <div>
                <strong>{locale === 'ar' ? 'ملفات الرفع الجماعي' : 'Batch media files'}</strong>
                <p>{locale === 'ar' ? 'اختر حتى 20 ملفًا للموقع الحالي.' : 'Select up to 20 files for this site.'}</p>
            </div>
            <input
                ref={fileInput}
                type="file"
                multiple
                aria-label={locale === 'ar' ? 'اختيار ملفات الوسائط' : 'Choose media files'}
                disabled={busy}
                onChange={(event) => {
                    const next = Array.from(event.currentTarget.files ?? []);
                    setFiles(next.length <= MEDIA_BATCH_MAX_FILES ? next : []);
                    setMessage(next.length > MEDIA_BATCH_MAX_FILES
                        ? (locale === 'ar' ? 'الحد الأقصى 20 ملفًا.' : 'Maximum 20 files per batch.')
                        : '');
                }}
            />
            {files.length > 0 && (
                <>
                    <p role="status">{locale === 'ar' ? `تم اختيار ${files.length} ملف` : `${files.length} file(s) selected`}</p>
                    <ul>{files.map((file, index) => <li key={`${index}:${file.name}:${file.size}`}>{file.name}</li>)}</ul>
                    <div className="toolbar-actions">
                        <button
                            type="button"
                            className="btn"
                            data-canonical-operation={MEDIA_BATCH_CLEAR_SELECTION_OPERATION_ID}
                            disabled={busy}
                            aria-busy={busy}
                            onClick={clearSelection}
                        >{locale === 'ar' ? 'إلغاء' : 'Cancel'}</button>
                        <button type="button" className="btn primary" disabled={busy} onClick={() => void enqueue()}>
                            {busy ? (locale === 'ar' ? 'جارٍ الإدراج…' : 'Queuing…') : (locale === 'ar' ? 'إدراج ملفات الرفع' : 'Queue selected files')}
                        </button>
                    </div>
                </>
            )}
            {message && <p role="status">{message}</p>}
        </section>
    );
}
