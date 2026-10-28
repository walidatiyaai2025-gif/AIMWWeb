import React, { useRef, useState } from 'react';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const MEDIA_BATCH_CLEAR_SELECTION_OPERATION_ID = 'AIMW-SYNC-B83AB5A666';
export const MEDIA_BATCH_UPLOAD_OPERATION_ID = 'AIMW-SYNC-6766C503DA';
export const MEDIA_BATCH_MAX_FILES = 20;
export const MEDIA_BATCH_MAX_BYTES = 25 * 1024 * 1024;

const allowedTypes: Record<string, string[]> = {
    jpg: ['image/jpeg'], jpeg: ['image/jpeg'], png: ['image/png'],
    gif: ['image/gif'], webp: ['image/webp'], avif: ['image/avif'],
    pdf: ['application/pdf'], doc: ['application/msword'],
    docx: ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    xls: ['application/vnd.ms-excel'],
    xlsx: ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    zip: ['application/zip', 'application/x-zip-compressed'],
};

export function inspectMediaBatchFile(file: File): { valid: boolean; message: string } {
    if (file.size <= 0) return { valid: false, message: 'The selected file is empty.' };
    if (file.size > MEDIA_BATCH_MAX_BYTES) return { valid: false, message: 'The file exceeds the 25 MB upload limit.' };
    const safeName = file.name.replaceAll('\\', '/').split('/').pop() ?? '';
    if (!safeName.trim() || safeName.length > 180) return { valid: false, message: 'The file name is invalid.' };
    const extension = safeName.split('.').pop()?.toLowerCase() ?? '';
    const allowed = allowedTypes[extension];
    if (!allowed) return { valid: false, message: 'This file type is not allowed for media upload.' };
    const type = (file.type || 'application/octet-stream').toLowerCase();
    if (type !== 'application/octet-stream' && !allowed.includes(type)) {
        return { valid: false, message: 'The file extension and content type do not match.' };
    }
    return { valid: true, message: '' };
}

type ActiveSite = { id: number };
type MediaContext = FrontendContext & { active_site?: ActiveSite | null };
type UploadState = 'queued' | 'succeeded' | 'failed' | 'rejected' | 'unknown';
type UploadResult = { name: string; state: UploadState; message: string; transferId?: number };
type TransferStatus = { id: number; state: string; last_error?: string | null };

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
 * UploadClickedAsync queues only valid files and exposes a truthful remote-status reread.
 */
export function MediaBatchClearSelectionControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const fileInput = useRef<HTMLInputElement>(null);
    const inFlight = useRef(false);
    const [files, setFiles] = useState<File[]>([]);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const [title, setTitle] = useState('');
    const [altText, setAltText] = useState('');
    const [caption, setCaption] = useState('');
    const [progress, setProgress] = useState<{ processed: number; total: number; queued: number; failed: number; rejected: number } | null>(null);
    const [results, setResults] = useState<UploadResult[]>([]);
    const endpoint = authorizedMediaUploadEndpoint(context);
    const inspected = files.map(file => ({ file, validation: inspectMediaBatchFile(file) }));
    const validCount = inspected.filter(item => item.validation.valid).length;
    const rejectedCount = inspected.length - validCount;
    if (!endpoint) return null;

    const clearSelection = () => {
        if (busy) return;
        setFiles([]);
        setMessage('');
        setResults([]);
        setProgress(null);
        setTitle('');
        setAltText('');
        setCaption('');
        if (fileInput.current) fileInput.current.value = '';
    };

    const enqueue = async () => {
        if (inFlight.current || busy || validCount === 0 || files.length > MEDIA_BATCH_MAX_FILES) return;
        inFlight.current = true;
        setBusy(true);
        setMessage('');
        setResults([]);
        const report: UploadResult[] = [];
        const failedFiles: File[] = [];
        let queued = 0;
        let failed = 0;
        let rejected = 0;

        try {
            for (const { file, validation } of inspected) {
                if (!validation.valid) {
                    rejected++;
                    report.push({ name: file.name, state: 'rejected', message: validation.message });
                } else {
                    const payload = new FormData();
                    payload.append('file', file, file.name);
                    if (files.length === 1) {
                        if (title.trim()) payload.append('title', title.trim());
                        if (altText.trim()) payload.append('alt_text', altText.trim());
                        if (caption.trim()) payload.append('caption', caption.trim());
                    }
                    try {
                        // The Laravel endpoint returns HTTP 202, not a remote WordPress success.
                        const transfer = await apiRequest<TransferStatus>(endpoint, { method: 'POST', body: payload });
                        if (!Number.isSafeInteger(transfer.id) || transfer.id <= 0 || transfer.state !== 'queued') {
                            throw new Error('The server did not confirm a durable queued transfer.');
                        }
                        queued++;
                        report.push({ name: file.name, state: 'queued', transferId: transfer.id, message: 'Queued; remote upload not yet verified.' });
                    } catch (error) {
                        failed++;
                        failedFiles.push(file);
                        report.push({ name: file.name, state: 'failed', message: error instanceof Error ? error.message : 'Request failed.' });
                    }
                }
                setProgress({ processed: report.length, total: inspected.length, queued, failed, rejected });
                setResults([...report]);
            }
            setFiles(failedFiles);
            if (fileInput.current) fileInput.current.value = '';
            setMessage(locale === 'ar'
                ? `تم إدراج ${queued} ملف، فشل ${failed}، ورُفض ${rejected}. راجع حالة المهام لتأكيد النتيجة.`
                : `${queued} file(s) queued, ${failed} failed, ${rejected} rejected. Check execution status for the remote result.`);
        } finally {
            inFlight.current = false;
            setBusy(false);
        }
    };

    const refreshStatus = async () => {
        if (inFlight.current || busy) return;
        inFlight.current = true;
        setBusy(true);
        try {
            const updated: UploadResult[] = [];
            for (const item of results) {
                if (item.state !== 'queued' || !item.transferId) {
                    updated.push(item);
                    continue;
                }
                try {
                    const path = `${endpoint.replace(/\\/media$/, '')}/transfers/${item.transferId}`;
                    const transfer = await apiRequest<TransferStatus>(path);
                    if (transfer.id !== item.transferId) throw new Error('Mismatched transfer response.');
                    const state: UploadState = transfer.state === 'completed' ? 'succeeded'
                        : transfer.state === 'failed' ? 'failed'
                        : ['queued', 'running'].includes(transfer.state) ? 'queued' : 'unknown';
                    updated.push({ ...item, state, message: state === 'succeeded'
                        ? 'WordPress upload and sync completed.'
                        : state === 'failed' ? (transfer.last_error || 'Remote upload failed.')
                        : state === 'queued' ? 'Still queued or running.' : 'Transfer state needs manual reconciliation.' });
                } catch (error) {
                    updated.push({ ...item, state: 'unknown', message: error instanceof Error ? error.message : 'Status could not be verified.' });
                }
            }
            setResults(updated);
        } finally {
            inFlight.current = false;
            setBusy(false);
        }
    };

    return (
        <section className="toolbar-panel" aria-label={locale === 'ar' ? 'الرفع الجماعي للوسائط' : 'Batch media upload'}>
            <div>
                <strong>{locale === 'ar' ? 'ملفات الرفع الجماعي' : 'Batch media files'}</strong>
                <p>{locale === 'ar' ? 'اختر حتى 20 ملفًا للموقع الحالي. الحد الأقصى 25 MB للملف.' : 'Select up to 20 files for this site (25 MB each).'}</p>
            </div>
            <input
                ref={fileInput}
                type="file"
                multiple
                accept=".jpg,.jpeg,.png,.gif,.webp,.avif,.pdf,.doc,.docx,.xls,.xlsx,.zip"
                aria-label={locale === 'ar' ? 'اختيار ملفات الوسائط' : 'Choose media files'}
                disabled={busy}
                onChange={(event) => {
                    const next = Array.from(event.currentTarget.files ?? []);
                    setFiles(next.length <= MEDIA_BATCH_MAX_FILES ? next : []);
                    setResults([]);
                    setProgress(null);
                    setTitle(next.length === 1 ? next[0].name.replace(/\\.[^.]+$/, '') : '');
                    setAltText('');
                    setCaption('');
                    setMessage(next.length > MEDIA_BATCH_MAX_FILES
                        ? (locale === 'ar' ? 'الحد الأقصى 20 ملفًا.' : 'Maximum 20 files per batch.')
                        : '');
                }}
            />
            {files.length > 0 && (
                <>
                    <p role="status">{locale === 'ar' ? `تم اختيار ${files.length} ملف` : `${files.length} file(s) selected`}</p>
                    <p>{locale === 'ar' ? `صالح: ${validCount} · مرفوض: ${rejectedCount}` : `Valid: ${validCount} · rejected: ${rejectedCount}`}</p>
                    <ul>{inspected.map(({ file, validation }, index) => (
                        <li key={`${index}:${file.name}:${file.size}`}>
                            {file.name} — {validation.valid ? (locale === 'ar' ? 'صالح' : 'Valid') : `Rejected: ${validation.message}`}
                        </li>
                    ))}</ul>
                    {files.length === 1 ? (
                        <div className="form-grid">
                            <label>Title <input aria-label="Title" disabled={busy} value={title} onChange={e => setTitle(e.target.value)} /></label>
                            <label>Alt text <input aria-label="Alt text" disabled={busy} value={altText} onChange={e => setAltText(e.target.value)} /></label>
                            <label>Caption <input aria-label="Caption" disabled={busy} value={caption} onChange={e => setCaption(e.target.value)} /></label>
                        </div>
                    ) : (
                        <p>{locale === 'ar' ? 'لن تُنسخ بيانات وصفية واحدة إلى جميع الملفات.' : 'Single-file metadata is never copied across a batch.'}</p>
                    )}
                    <div className="toolbar-actions">
                        <button type="button" className="btn"
                            data-canonical-operation={MEDIA_BATCH_CLEAR_SELECTION_OPERATION_ID}
                            disabled={busy} aria-busy={busy} onClick={clearSelection}>
                            {locale === 'ar' ? 'إلغاء' : 'Cancel'}
                        </button>
                        <button type="button" className="btn primary"
                            data-canonical-operation={MEDIA_BATCH_UPLOAD_OPERATION_ID}
                            disabled={busy || validCount === 0} aria-busy={busy} onClick={() => void enqueue()}>
                            {busy ? (locale === 'ar' ? 'جارٍ الإدراج…' : 'Queuing…')
                                : (locale === 'ar' ? 'إدراج ملفات الرفع' : 'Queue selected files')}
                        </button>
                    </div>
                </>
            )}
            {progress && <p role="status" aria-live="polite">
                {locale === 'ar' ? 'التقدم' : 'Progress'}: {progress.processed}/{progress.total}
                {' · '}{progress.queued} queued · {progress.failed} failed · {progress.rejected} rejected
            </p>}
            {results.length > 0 && <div aria-label="Last batch result">
                <strong>{locale === 'ar' ? 'نتيجة آخر دفعة' : 'Last batch result'}</strong>
                <ul>{results.map((item, index) => <li key={`${index}:${item.name}`}>
                    {item.name}: {item.state} — {item.message}
                </li>)}</ul>
                {results.some(item => item.state === 'queued') && (
                    <button type="button" disabled={busy} onClick={() => void refreshStatus()}>
                        {locale === 'ar' ? 'تحديث حالة الرفع' : 'Refresh upload status'}
                    </button>
                )}
            </div>}
            {message && <p role="status">{message}</p>}
        </section>
    );
}
