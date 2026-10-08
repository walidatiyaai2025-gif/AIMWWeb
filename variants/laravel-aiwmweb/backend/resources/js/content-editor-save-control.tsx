import React, { FormEvent, useEffect, useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import { ApiError, apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CONTENT_EDITOR_SAVE_OPERATION_ID = 'AIMW-BILL-42F7590F00';
export const CONTENT_EDITOR_SUBMIT_APPROVAL_OPERATION_ID = 'AIMW-BILL-F5686193FE';

export type ContentEditorSnapshot = {
    id: number;
    wordpress_id: number;
    type: 'post' | 'page';
    title: string;
    slug: string;
    content: string;
    excerpt: string;
    status: 'draft' | 'pending' | 'publish' | 'future' | 'private';
    date_gmt?: string | null;
    featured_media: number;
    categories: number[];
    tags: number[];
    template: string;
    comment_status: 'open' | 'closed';
    ping_status: 'open' | 'closed';
    format: string;
    sticky: boolean;
    link?: string;
    expected_hash?: string | null;
    expected_modified_at?: string | null;
    expected_version?: string | null;
    reconciled_at?: string | null;
};

export type ContentEditorDraft = Omit<
    ContentEditorSnapshot,
    'id' | 'wordpress_id' | 'type' | 'link' | 'expected_hash' | 'expected_modified_at' | 'expected_version' | 'reconciled_at'
> & {
    categories_text: string;
    tags_text: string;
};

export function contentEditorEndpoint(
    tenantSlug: string,
    siteId: string | number,
    contentType: string,
    wordpressId: string | number,
): string | null {
    const site = String(siteId).trim();
    const remote = String(wordpressId).trim();
    const type = contentType.trim().toLowerCase();
    if (!tenantSlug.trim() || /[\\/]/.test(tenantSlug)) return null;
    if (!/^[1-9]\d*$/.test(site) || !/^[1-9]\d*$/.test(remote)) return null;
    if (!['post', 'page'].includes(type)) return null;

    return `/api/v1/tenants/${encodeURIComponent(tenantSlug)}/sites/${encodeURIComponent(site)}/content/${type}/${encodeURIComponent(remote)}/editor`;
}

export function contentEditorApprovalEndpoint(
    tenantSlug: string,
    siteId: string | number,
    contentType: string,
    wordpressId: string | number,
): string | null {
    const editor = contentEditorEndpoint(tenantSlug, siteId, contentType, wordpressId);

    return editor ? `${editor}/approval` : null;
}

function parseIds(value: string): number[] {
    return Array.from(new Set(
        value
            .split(',')
            .map((entry) => Number.parseInt(entry.trim(), 10))
            .filter((id) => Number.isInteger(id) && id > 0),
    ));
}

function toLocalInput(value?: string | null): string {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const offset = date.getTimezoneOffset() * 60_000;
    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

function fromSnapshot(snapshot: ContentEditorSnapshot): ContentEditorDraft {
    return {
        title: snapshot.title ?? '',
        slug: snapshot.slug ?? '',
        content: snapshot.content ?? '',
        excerpt: snapshot.excerpt ?? '',
        status: snapshot.status ?? 'draft',
        date_gmt: toLocalInput(snapshot.date_gmt),
        featured_media: Number(snapshot.featured_media ?? 0),
        categories: snapshot.categories ?? [],
        tags: snapshot.tags ?? [],
        categories_text: (snapshot.categories ?? []).join(','),
        tags_text: (snapshot.tags ?? []).join(','),
        template: snapshot.template ?? '',
        comment_status: snapshot.comment_status ?? 'open',
        ping_status: snapshot.ping_status ?? 'open',
        format: snapshot.format ?? 'standard',
        sticky: Boolean(snapshot.sticky),
    };
}

export function buildContentEditorSavePayload(snapshot: ContentEditorSnapshot, draft: ContentEditorDraft) {
    return {
        title: draft.title,
        slug: draft.slug || null,
        content: draft.content || null,
        excerpt: draft.excerpt || null,
        status: draft.status,
        date_gmt: draft.date_gmt ? new Date(draft.date_gmt).toISOString() : null,
        featured_media: Number(draft.featured_media || 0),
        categories: parseIds(draft.categories_text),
        tags: parseIds(draft.tags_text),
        template: draft.template || null,
        comment_status: draft.comment_status,
        ping_status: draft.ping_status,
        format: draft.format || null,
        sticky: Boolean(draft.sticky),
        expected_hash: snapshot.expected_hash ?? null,
        expected_modified_at: snapshot.expected_modified_at ?? null,
        expected_version: snapshot.expected_version ?? null,
    };
}

export type ContentEditorApprovalResponse = {
    data: {
        id: number;
        status: string;
        site_id: number;
        site_name: string;
        operation_type: string;
        title: string;
        risk_level: string;
        request_key: string;
        created_at?: string | null;
    };
    replayed: boolean;
};

export function buildContentEditorApprovalPayload(
    snapshot: ContentEditorSnapshot,
    draft: ContentEditorDraft,
    requestKey: string,
) {
    return {
        request_key: requestKey,
        ...buildContentEditorSavePayload(snapshot, draft),
    };
}

export function ContentEditorSaveControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const { siteId, contentType, wordpressId } = useParams();
    const endpoint = useMemo(
        () => contentEditorEndpoint(context.tenant.slug, siteId ?? '', contentType ?? '', wordpressId ?? ''),
        [context.tenant.slug, siteId, contentType, wordpressId],
    );
    const canEdit = context.permissions.includes('*') || context.permissions.includes('content.edit');
    const canView = canEdit || context.permissions.includes('content.view');
    const [snapshot, setSnapshot] = useState<ContentEditorSnapshot | null>(null);
    const [draft, setDraft] = useState<ContentEditorDraft | null>(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [submittingApproval, setSubmittingApproval] = useState(false);
    const [error, setError] = useState('');
    const [message, setMessage] = useState('');

    const reload = async (): Promise<ContentEditorSnapshot> => {
        if (!endpoint) throw new ApiError('Invalid content editor route.', 404, 'invalid_editor_route');
        const current = await apiRequest<ContentEditorSnapshot>(endpoint);
        if (Number(current.wordpress_id) !== Number(wordpressId) || current.type !== contentType) {
            throw new ApiError('Authoritative content did not match the requested editor route.', 409, 'editor_content_mismatch');
        }
        setSnapshot(current);
        setDraft(fromSnapshot(current));
        return current;
    };

    useEffect(() => {
        let active = true;
        setLoading(true);
        setError('');
        if (!canView) {
            setLoading(false);
            setError(locale === 'ar' ? 'تحتاج صلاحية عرض المحتوى.' : 'Content view permission is required.');
            return () => { active = false; };
        }
        reload()
            .catch((cause: unknown) => {
                if (active) setError(cause instanceof Error ? cause.message : 'Content editor unavailable.');
            })
            .finally(() => {
                if (active) setLoading(false);
            });
        return () => { active = false; };
    }, [endpoint, canView]);

    const set = <K extends keyof ContentEditorDraft>(key: K, value: ContentEditorDraft[K]) => {
        setDraft((current) => current ? { ...current, [key]: value } : current);
    };

    const save = async (event: FormEvent) => {
        event.preventDefault();
        if (!endpoint || !snapshot || !draft || !canEdit || saving || submittingApproval) return;

        setSaving(true);
        setError('');
        setMessage('');
        try {
            const payload = buildContentEditorSavePayload(snapshot, draft);
            await apiRequest<ContentEditorSnapshot>(endpoint, {
                method: 'PATCH',
                body: JSON.stringify(payload),
            });

            const authoritative = await reload();
            setMessage(locale === 'ar'
                ? `تم الحفظ في WordPress وإعادة قراءة النسخة المؤكدة #${authoritative.wordpress_id}.`
                : `Saved to WordPress and reconciled authoritative content #${authoritative.wordpress_id}.`);
        } catch (cause: unknown) {
            setMessage('');
            setError(cause instanceof Error ? cause.message : 'Content save failed.');
        } finally {
            setSaving(false);
        }
    };

    const submitForApproval = async () => {
        const approvalEndpoint = contentEditorApprovalEndpoint(
            context.tenant.slug,
            siteId ?? '',
            contentType ?? '',
            wordpressId ?? '',
        );
        if (!approvalEndpoint || !snapshot || !draft || !canEdit || saving || submittingApproval) return;

        setSubmittingApproval(true);
        setError('');
        setMessage('');
        try {
            const response = await apiRequest<ContentEditorApprovalResponse>(approvalEndpoint, {
                method: 'POST',
                body: JSON.stringify(buildContentEditorApprovalPayload(snapshot, draft, crypto.randomUUID())),
            });
            setMessage(locale === 'ar'
                ? `تم إرسال التعديل للموافقة. رقم الطلب: ${response.data.id}.`
                : `Change submitted for approval. Request: ${response.data.id}.`);
        } catch (cause: unknown) {
            setMessage('');
            setError(cause instanceof Error ? cause.message : 'Approval submission failed.');
        } finally {
            setSubmittingApproval(false);
        }
    };

    if (!endpoint) {
        return <div className="state-panel state-warning">{locale === 'ar' ? 'مسار محرر المحتوى غير صالح.' : 'Invalid content editor route.'}</div>;
    }
    if (loading) {
        return <div className="state-panel">{locale === 'ar' ? 'جارٍ تحميل المحتوى الفعلي…' : 'Loading authoritative content…'}</div>;
    }
    if (!snapshot || !draft) {
        return <div role="alert" className="state-panel state-danger">{error || (locale === 'ar' ? 'تعذر تحميل المحتوى.' : 'Content could not be loaded.')}</div>;
    }

    return (
        <form className="workspace-stack" onSubmit={save} data-canonical-operation={CONTENT_EDITOR_SAVE_OPERATION_ID}>
            <section className="hero-panel">
                <div>
                    <span className="workspace-kicker">CONTENT EDITOR</span>
                    <h2>{draft.title || `${snapshot.type} #${snapshot.wordpress_id}`}</h2>
                    <p>{locale === 'ar'
                        ? 'تحرير مباشر مع منع الكتابة فوق نسخة WordPress أحدث وإعادة قراءة الحالة بعد الحفظ.'
                        : 'Direct editing with optimistic conflict protection and authoritative reread after save.'}</p>
                </div>
                {canEdit ? (
                    <div className="cluster">
                        <button
                            type="button"
                            className="btn"
                            disabled={saving || submittingApproval || !draft.title.trim()}
                            data-canonical-operation={CONTENT_EDITOR_SUBMIT_APPROVAL_OPERATION_ID}
                            onClick={submitForApproval}
                        >
                            {submittingApproval
                                ? (locale === 'ar' ? 'جارٍ الإرسال…' : 'Submitting…')
                                : (locale === 'ar' ? 'إرسال للموافقة' : 'Submit for approval')}
                        </button>
                        <button
                            type="submit"
                            className="btn primary"
                            disabled={saving || submittingApproval || !draft.title.trim()}
                            data-canonical-operation={CONTENT_EDITOR_SAVE_OPERATION_ID}
                        >
                            {saving ? (locale === 'ar' ? 'جارٍ الحفظ…' : 'Saving…') : (locale === 'ar' ? 'حفظ إلى WordPress' : 'Save to WordPress')}
                        </button>
                    </div>
                ) : null}
            </section>

            {!canEdit ? <div className="state-panel state-warning">{locale === 'ar' ? 'وضع القراءة فقط: تحتاج صلاحية تعديل المحتوى.' : 'Read-only mode: content edit permission is required.'}</div> : null}
            {error ? <div role="alert" className="state-panel state-danger">{error}</div> : null}
            {message ? <div role="status" className="state-panel state-success">{message}</div> : null}

            <fieldset disabled={!canEdit || saving || submittingApproval} className="panel form-stack">
                <label><span>{locale === 'ar' ? 'العنوان' : 'Title'}</span><input required value={draft.title} onChange={(e) => set('title', e.target.value)} /></label>
                <label><span>Slug</span><input value={draft.slug} onChange={(e) => set('slug', e.target.value)} /></label>
                <label><span>{locale === 'ar' ? 'المحتوى HTML' : 'Content HTML'}</span><textarea rows={14} value={draft.content} onChange={(e) => set('content', e.target.value)} /></label>
                <label><span>{locale === 'ar' ? 'الملخص' : 'Excerpt'}</span><textarea rows={4} value={draft.excerpt} onChange={(e) => set('excerpt', e.target.value)} /></label>
                <label><span>{locale === 'ar' ? 'الحالة' : 'Status'}</span>
                    <select value={draft.status} onChange={(e) => set('status', e.target.value as ContentEditorDraft['status'])}>
                        <option value="draft">draft</option>
                        <option value="pending">pending</option>
                        <option value="publish">publish</option>
                        <option value="future">future</option>
                        <option value="private">private</option>
                    </select>
                </label>
                <label><span>{locale === 'ar' ? 'تاريخ النشر' : 'Publish date'}</span><input type="datetime-local" value={draft.date_gmt ?? ''} onChange={(e) => set('date_gmt', e.target.value)} /></label>
                <label><span>{locale === 'ar' ? 'معرف الصورة البارزة' : 'Featured media ID'}</span><input type="number" min={0} value={draft.featured_media} onChange={(e) => set('featured_media', Number(e.target.value))} /></label>
                {snapshot.type === 'post' ? (
                    <>
                        <label><span>{locale === 'ar' ? 'معرفات التصنيفات' : 'Category IDs'}</span><input value={draft.categories_text} onChange={(e) => set('categories_text', e.target.value)} /></label>
                        <label><span>{locale === 'ar' ? 'معرفات الوسوم' : 'Tag IDs'}</span><input value={draft.tags_text} onChange={(e) => set('tags_text', e.target.value)} /></label>
                    </>
                ) : null}
                <label><span>{locale === 'ar' ? 'القالب' : 'Template'}</span><input value={draft.template} onChange={(e) => set('template', e.target.value)} /></label>
                <label><span>{locale === 'ar' ? 'التعليقات' : 'Comment status'}</span>
                    <select value={draft.comment_status} onChange={(e) => set('comment_status', e.target.value as 'open' | 'closed')}>
                        <option value="open">open</option><option value="closed">closed</option>
                    </select>
                </label>
                <label><span>Ping status</span>
                    <select value={draft.ping_status} onChange={(e) => set('ping_status', e.target.value as 'open' | 'closed')}>
                        <option value="open">open</option><option value="closed">closed</option>
                    </select>
                </label>
                {snapshot.type === 'post' ? (
                    <>
                        <label><span>Format</span><input value={draft.format} onChange={(e) => set('format', e.target.value)} /></label>
                        <label><input type="checkbox" checked={draft.sticky} onChange={(e) => set('sticky', e.target.checked)} /> <span>Sticky</span></label>
                    </>
                ) : null}
            </fieldset>

            {snapshot.link ? <a className="btn" href={snapshot.link} target="_blank" rel="noreferrer">{locale === 'ar' ? 'عرض على WordPress' : 'View on WordPress'} ↗</a> : null}
        </form>
    );
}
