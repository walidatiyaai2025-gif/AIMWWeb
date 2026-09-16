import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const COMMENTS_COMMENT_LINK_OPERATION_ID = 'AIMW-COMM-B16FBF4792';

type CommentsEnvelope = {
    data?: Array<Record<string, unknown>>;
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
    if (!linkedRows.length) return null;

    return (
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
    );
}
