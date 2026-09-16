import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    COMMENTS_COMMENT_LINK_OPERATION_ID,
    CommentsCommentLinksControl,
    safeExternalCommentUrl,
} from '../comments-comment-link-control';

function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'content.view'],
        connectors: [],
        capabilities: {},
        api: { comments: '/api/v1/tenants/alpha/sites/17/comments' },
        actions: {},
    };
}

function renderControl(activeContext = context()) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <CommentsCommentLinksControl context={activeContext} />
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('canonical Comments external comment Link control', () => {
    it('uses the authoritative tenant/site comments read and renders only safe persisted destinations', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            data: [
                { id: 1, author_name: 'Alice', link: 'https://alpha.example.test/post#comment-1' },
                { id: 2, author_name: 'Mallory', link: 'javascript:alert(1)' },
            ],
        }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        const link = await screen.findByRole('link', { name: /open/i });
        expect(link).toHaveAttribute('href', 'https://alpha.example.test/post#comment-1');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        expect(link.closest('[data-canonical-operation]')).toHaveAttribute('data-canonical-operation', COMMENTS_COMMENT_LINK_OPERATION_ID);
        expect(COMMENTS_COMMENT_LINK_OPERATION_ID).toBe('AIMW-COMM-B16FBF4792');
        expect(screen.queryByText('Mallory')).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/v1/tenants/alpha/sites/17/comments?page=1');
        expect(fetchMock.mock.calls[0]?.[1]).not.toHaveProperty('method');
    });

    it('fails closed when the server returns only unsafe or malformed comment links', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            data: [
                { id: 1, link: 'data:text/html,hello' },
                { id: 2, link: '/relative/comment' },
                { id: 3, link: 'https://user:secret@example.test/comment' },
            ],
        }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Comment links')).not.toBeInTheDocument();
    });

    it('does not fetch or synthesize a destination when the authoritative comments endpoint is absent', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const activeContext = context();
        activeContext.api = {};

        renderControl(activeContext);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
});

describe('safeExternalCommentUrl', () => {
    it('allows absolute http/https only and rejects whitespace, credentials, unsafe schemes, relative and malformed URLs', () => {
        expect(safeExternalCommentUrl('https://alpha.example.test/post#comment-7')).toBe('https://alpha.example.test/post#comment-7');
        expect(safeExternalCommentUrl('http://alpha.example.test/comment')).toBe('http://alpha.example.test/comment');
        expect(safeExternalCommentUrl('javascript:alert(1)')).toBeNull();
        expect(safeExternalCommentUrl('data:text/html,hello')).toBeNull();
        expect(safeExternalCommentUrl('file:///etc/passwd')).toBeNull();
        expect(safeExternalCommentUrl('/relative/comment')).toBeNull();
        expect(safeExternalCommentUrl(' https://alpha.example.test/comment')).toBeNull();
        expect(safeExternalCommentUrl('https://user:secret@example.test/comment')).toBeNull();
        expect(safeExternalCommentUrl('not a url')).toBeNull();
    });
});
