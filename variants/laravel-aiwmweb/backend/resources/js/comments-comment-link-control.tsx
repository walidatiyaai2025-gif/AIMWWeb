import React, { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const COMMENTS_COMMENT_LINK_OPERATION_ID = 'AIMW-COMM-B16FBF4792';
export const COMMENTS_CANCEL_REPLY_OPERATION_ID = 'AIMW-COMM-843A2F029B';

type CommentRow = Record<string, unknown>;

type CommentsEnvelope = {
    data?: CommentRow[];
};

export function safeExternalCommentUrl(value: unknown): string | null {
    if (typeof value !== 'string' || value.trim() !== value || value.length === 0) return null;

    try {
        const parsed = new URL(value);
        if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return null;
        if (parsed.username || parsed.password) return null;
        return value;
    } catch {
        return null;
    }
}

function firstPageEndpoint(endpoint: string): string {
    const url = new URL(endpoint, window.location.origin);
    url.searchParams.set('page', '1');
    return `${url.pathname}${url.search}`;
}

function commentId(value: unknown): number | null {
    const id = typeof value === 'number' ? value : Number(value);
    return Number.isSafeInteger(id) && id > 0 ? id : null;
}

export function commentReplyEndpoint(endpoint: string, idValue: unknown): string | null {
    const id = commentId(idValue);
    if (!id) return null;

    try {
        const url = new URL(endpoint, window.location.origin);
        if (url.origin !== window.location.origin) return null;
        const path = url.pathname.replace(/\/+$/, '');
        if (!path.endsWith('/comments')) return null;
        return `${path}/${id}/reply`;
    } catch {
        return null;
    }
}

export function CommentsCommentLinkControl({ value }: { value: unknown }) {
    const { locale } = useLocale();
    const safeUrl = safeExternalCommentUrl(value);

    return (
        <span data-canonical-operation={COMMENTS_COMMENT_LINK_OPERATION_ID}>
            {safeUrl ? (
                <a href={safeUrl} target="_blank" rel="noopener noreferrer">
                    {locale === 'ar' ? 'فتح' : 'Open'} ↗
                </a>
            ) : (
                <span aria-label={locale === 'ar' ? 'رابط التعليق غير متاح' : 'Comment link unavailable'}>—</span>
            )}
        </span>
    );
}

function CommentsReplyDraft({
    endpoint,
    rows,
    canEdit,
    onAuthoritativeRefresh,
}: {
    endpoint: string;
    rows: CommentRow[];
    canEdit: boolean;
    onAuthoritativeRefresh: () => Promise<unknown>;
}) {
    const { locale } = useLocale();
    const [replyingTo, setReplyingTo] = useState<number | null>(null);
    const [draft, setDraft] = useState('');
    const [clientError, setClientError] = useState('');

    const replyRows = rows
        .map((row) => ({ row, id: commentId(row.id) }))
        .filter((entry): entry is { row: CommentRow; id: number } => entry.id !== null);

    const mutation = useMutation({
        mutationFn: async ({ id, content }: { id: number; content: string }) => {
            const target = commentReplyEndpoint(endpoint, id);
            if (!target) throw new Error('The comment reply endpoint is unavailable.');
            return apiRequest<Record<string, unknown>>(target, {
                method: 'POST',
                body: JSON.stringify({ content }),
            });
        },
        retry: false,
        onSuccess: async () => {
            await onAuthoritativeRefresh();
            setReplyingTo(null);
            setDraft('');
            setClientError('');
        },
    });

    if (!canEdit || replyRows.length === 0) return null;

    const selected = replyRows.find((entry) => entry.id === replyingTo) ?? null;
    const cancelReply = () => {
        if (mutation.isPending) return;
        setReplyingTo(null);
        setDraft('');
        setClientError('');
    };
    const sendReply = () => {
        if (!selected || mutation.isPending) return;
        const content = draft.trim();
        if (!content || content.length > 20000) {
            setClientError(locale === 'ar' ? 'اكتب ردًا من 1 إلى 20000 حرف.' : 'Enter a reply between 1 and 20000 characters.');
            return;
        }
        setClientError('');
        mutation.mutate({ id: selected.id, content });
    };

    return (
        <section className="panel comments-reply-draft" aria-label={locale === 'ar' ? 'الرد على تعليق' : 'Comment reply'}>
            <header className="panel-header">
                <div>
                    <span className="workspace-kicker">WORDPRESS</span>
                    <strong>{locale === 'ar' ? 'رد على تعليق' : 'Reply to comment'}</strong>
                </div>
            </header>
            {!selected ? (
                <ul>
                    {replyRows.map(({ row, id }) => (
                        <li key={id}>
                            <span>{String(row.author_name ?? row.id ?? id)}</span>{' '}
                            <button type="button" onClick={() => { setReplyingTo(id); setDraft(''); setClientError(''); }}>
                                {locale === 'ar' ? 'رد' : 'Reply'}
                            </button>
                        </li>
                    ))}
                </ul>
            ) : (
                <div className="comments-reply-editor">
                    <label>
                        <span>{locale === 'ar' ? 'نص الرد' : 'Reply content'}</span>
                        <textarea
                            aria-label={locale === 'ar' ? 'نص الرد' : 'Reply content'}
                            maxLength={20000}
                            value={draft}
                            disabled={mutation.isPending}
                            onChange={(event) => setDraft(event.currentTarget.value)}
                        />
                    </label>
                    {clientError ? <p role="alert">{clientError}</p> : null}
                    {mutation.error ? <p role="alert">{mutation.error instanceof Error ? mutation.error.message : 'Reply failed.'}</p> : null}
                    <div className="actions">
                        <button type="button" disabled={mutation.isPending} onClick={sendReply}>
                            {mutation.isPending ? (locale === 'ar' ? 'جارٍ الإرسال…' : 'Sending…') : (locale === 'ar' ? 'إرسال الرد' : 'Send reply')}
                        </button>
                        <button
                            type="button"
                            data-canonical-operation={COMMENTS_CANCEL_REPLY_OPERATION_ID}
                            disabled={mutation.isPending}
                            onClick={cancelReply}
                        >
                            {locale === 'ar' ? 'إلغاء' : 'Cancel'}
                        </button>
                    </div>
                </div>
            )}
        </section>
    );
}

export function CommentsCommentLinksControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const endpoint = context.api.comments;
    const query = useQuery({
        queryKey: ['workspace', context.tenant.slug, 'comments', endpoint, 1, ''],
        queryFn: () => apiRequest<CommentsEnvelope>(firstPageEndpoint(endpoint!)),
        enabled: Boolean(endpoint),
    });

    if (!endpoint || query.isLoading || query.error) return null;

    const rows = Array.isArray(query.data?.data) ? query.data.data : [];
    const linkedRows = rows.filter((row) => safeExternalCommentUrl(row.link));
    const canEdit = context.permissions.includes('*') || context.permissions.includes('content.edit');

    if (!linkedRows.length && (!canEdit || !rows.some((row) => commentId(row.id)))) return null;

    return (
        <>
            <CommentsReplyDraft
                endpoint={endpoint}
                rows={rows}
                canEdit={canEdit}
                onAuthoritativeRefresh={() => query.refetch()}
            />
            {linkedRows.length ? (
                <section className="panel comments-comment-links" aria-label={locale === 'ar' ? 'روابط التعليقات' : 'Comment links'}>
                    <header className="panel-header">
                        <div>
                            <span className="workspace-kicker">WORDPRESS</span>
                            <strong>{locale === 'ar' ? 'فتح التعليق الأصلي' : 'Open original comment'}</strong>
                        </div>
                    </header>
                    <ul>
                        {linkedRows.map((row, index) => (
                            <li key={String(row.id ?? row.remote_id ?? index)}>
                                <span>{String(row.author_name ?? row.id ?? index + 1)}</span>{' '}
                                <CommentsCommentLinkControl value={row.link} />
                            </li>
                        ))}
                    </ul>
                </section>
            ) : null}
        </>
    );
}
