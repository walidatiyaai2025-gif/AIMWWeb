import React, { useState } from 'react';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';
import { MediaAltSuggestionControl, mediaSuggestionSiteId } from './media-alt-suggestion-control';

export const MEDIA_SAVE_OPTIMIZATION_OPERATION_ID = 'AIMW-SYNC-425B82511E';
export const MEDIA_CANCEL_OPTIMIZATION_OPERATION_ID = 'AIMW-SYNC-4B986B2651';

type Metadata = { title: string; slug: string; alt_text: string; caption: string; description: string };
type VerifiedMutation = { verified?: boolean; remote_id?: number };

function textField(value: unknown): string {
    if (typeof value === 'string') return value;
    if (value && typeof value === 'object' && 'raw' in value && typeof (value as {raw?: unknown}).raw === 'string')
        return (value as {raw: string}).raw;
    return '';
}

export function originalMediaMetadata(row: Record<string, unknown>): Metadata {
    return {
        title: textField(row.title),
        slug: textField(row.slug),
        alt_text: textField(row.alt_text),
        caption: textField(row.caption),
        description: textField(row.description),
    };
}

export function MediaOptimizationEditorControl({
    context, endpoint, row, onReconcile,
}: {
    context: FrontendContext;
    endpoint: string;
    row: Record<string, unknown>;
    onReconcile: () => Promise<void>;
}) {
    const { locale } = useLocale();
    const [open, setOpen] = useState(false);
    const [expectedModifiedGmt, setExpectedModifiedGmt] = useState('');
    const [draft, setDraft] = useState<Metadata>(() => originalMediaMetadata(row));
    const [working, setWorking] = useState(false);
    const [opening, setOpening] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const mediaId = Number(row.id);
    const siteId = mediaSuggestionSiteId(context, endpoint, row);
    const authorized = context.permissions.includes('*') || context.permissions.includes('content.edit');
    const isArabic = locale === 'ar';
    if (!authorized || siteId === null || !Number.isSafeInteger(mediaId) || mediaId < 1) return null;

    const edit = (key: keyof Metadata, value: string) => setDraft((old) => ({ ...old, [key]: value }));

    const openEditor = async () => {
        if (opening || working) return;
        setOpening(true);
        setError('');
        setNotice('');
        try {
            const details = await apiRequest<Metadata & { remote_id: number; modified_gmt: string }>(`${endpoint}/${mediaId}`);
            if (details.remote_id !== Number(row.remote_id) || !details.modified_gmt?.trim()
                || ['title', 'slug', 'alt_text', 'caption', 'description'].some((field) => typeof details[field as keyof Metadata] !== 'string')) {
                throw new Error('WordPress returned unexpected or incomplete editable metadata.');
            }
            setExpectedModifiedGmt(details.modified_gmt);
            setDraft({
                title: details.title,
                slug: details.slug,
                alt_text: details.alt_text,
                caption: details.caption,
                description: details.description,
            });
            setOpen(true);
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : 'Unable to read current WordPress media.');
        } finally {
            setOpening(false);
        }
    };

    // CancelOptimizationClickedAsync is local-only. Do not mutate WordPress or
    // invalidate the server cache when a user discards an uncommitted draft.
    const cancelOptimization = () => {
        if (working || opening) return;
        setDraft(originalMediaMetadata(row));
        setExpectedModifiedGmt('');
        setError('');
        setNotice('');
        setOpen(false);
    };

    const save = async () => {
        if (working || !draft.title.trim() || !expectedModifiedGmt) return;
        setWorking(true);
        setError('');
        setNotice('');
        try {
            const result = await apiRequest<VerifiedMutation>(`${endpoint}/${mediaId}`, {
                method: 'PATCH',
                body: JSON.stringify({ ...draft, expected_modified_gmt: expectedModifiedGmt }),
            });
            if (result.verified !== true || result.remote_id !== Number(row.remote_id)) {
                throw new Error('WordPress has not verified this metadata update.');
            }
            try {
                await onReconcile();
                setNotice(isArabic ? 'تم تأكيد التحسينات من WordPress.' : 'WordPress verified the optimization.');
            } catch {
                setNotice(isArabic ? 'تم تأكيد التعديل من WordPress، لكن تعذر تحديث القائمة المحلية.' : 'WordPress verified the update, but the local list could not refresh.');
            }
            setOpen(false);
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : 'Metadata save failed.');
        } finally {
            setWorking(false);
        }
    };

    return (
        <section className="media-optimization-editor">
            <button type="button" className="btn" disabled={working || opening} onClick={() => void openEditor()}>
                {opening ? (isArabic ? 'جارٍ تحميل البيانات…' : 'Loading metadata…') : (isArabic ? 'تحسين بيانات الوسيط' : 'Optimize metadata')}
            </button>
            {!open && error ? <p role="alert">{error}</p> : null}
            {notice ? <p role="status">{notice}</p> : null}
            {open ? <div className="panel workspace-stack" aria-label={isArabic ? 'تحسين الوسائط' : 'Media optimization'}>
                <h3>{isArabic ? 'تحسين بيانات الوسيط' : 'Optimize media metadata'} #{String(row.remote_id ?? mediaId)}</h3>
                <label><span>{isArabic ? 'العنوان' : 'Title'}</span>
                    <input aria-label="Media title" value={draft.title} maxLength={500} disabled={working} onChange={(event) => edit('title', event.target.value)} />
                </label>
                <label><span>Slug</span>
                    <input aria-label="Media slug" value={draft.slug} maxLength={200} disabled={working} onChange={(event) => edit('slug', event.target.value)} />
                </label>
                <label><span>Alt text</span>
                    <input aria-label="Media alt text" value={draft.alt_text} maxLength={500} disabled={working} onChange={(event) => edit('alt_text', event.target.value)} />
                </label>
                <label><span>{isArabic ? 'التعليق' : 'Caption'}</span>
                    <textarea aria-label="Media caption" value={draft.caption} disabled={working} onChange={(event) => edit('caption', event.target.value)} />
                </label>
                <label><span>{isArabic ? 'الوصف' : 'Description'}</span>
                    <textarea aria-label="Media description" value={draft.description} disabled={working} onChange={(event) => edit('description', event.target.value)} />
                </label>
                <MediaAltSuggestionControl context={context} endpoint={endpoint} row={row} onSuggestion={(value) => edit('alt_text', value)} />
                {error ? <p role="alert">{error}</p> : null}
                <button type="button" className="btn primary" data-canonical-operation={MEDIA_SAVE_OPTIMIZATION_OPERATION_ID}
                    disabled={working || !draft.title.trim() || !expectedModifiedGmt} aria-busy={working} onClick={() => void save()}>
                    {working ? (isArabic ? 'جارٍ الحفظ…' : 'Saving…') : (isArabic ? 'حفظ التحسينات' : 'Save optimization')}
                </button>
                <button type="button" className="btn" data-canonical-operation={MEDIA_CANCEL_OPTIMIZATION_OPERATION_ID}
                    disabled={working || opening} onClick={cancelOptimization}>
                    {isArabic ? 'إلغاء' : 'Cancel optimization'}
                </button>
            </div> : null}
        </section>
    );
}
