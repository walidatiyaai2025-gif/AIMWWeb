import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    COMMENTS_CANCEL_REPLY_OPERATION_ID,
    CommentsCommentLinksControl,
    commentReplyEndpoint,
} from '../comments-comment-link-control';

function context(permissions = ['content.view', 'content.edit']): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions,
        connectors: [],
        capabilities: {},
        api: { comments: '/api/v1/tenants/alpha/sites/17/comments' },
        actions: {},
    };
}

function renderControl(activeContext = context()) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <CommentsCommentLinksControl context={activeContext} />
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

function commentsResponse() {
    return new Response(JSON.stringify({ data: [{ id: 9, author_name: 'Alice', link: null }] }), {
        status: 200,
        headers: { 'content-type': 'application/json' },
    });
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('canonical Comments CancelReplyClicked control', () => {
    it('opens a draft for a real comment and cancel clears the local draft without any mutation request', async () => {
        const fetchMock = vi.fn().mockResolvedValue(commentsResponse());
        vi.stubGlobal('fetch', fetchMock);
        renderControl();

        fireEvent.click(await screen.findByRole('button', { name: 'Reply' }));
        const draft = screen.getByRole('textbox', { name: 'Reply content' });
        fireEvent.change(draft, { target: { value: 'draft that must be discarded' } });

        const cancel = screen.getByRole('button', { name: 'Cancel' });
        expect(cancel).toHaveAttribute('data-canonical-operation', COMMENTS_CANCEL_REPLY_OPERATION_ID);
        expect(COMMENTS_CANCEL_REPLY_OPERATION_ID).toBe('AIMW-COMM-843A2F029B');
        fireEvent.click(cancel);

        expect(screen.queryByRole('textbox', { name: 'Reply content' })).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/v1/tenants/alpha/sites/17/comments?page=1');
        expect(fetchMock.mock.calls.some((call) => call[1]?.method === 'POST')).toBe(false);
    });

    it('does not expose reply or cancel to a user without content.edit', async () => {
        const fetchMock = vi.fn().mockResolvedValue(commentsResponse());
        vi.stubGlobal('fetch', fetchMock);
        renderControl(context(['content.view']));

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(screen.queryByRole('button', { name: 'Reply' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();
    });

    it('uses the server-derived same-origin comments endpoint for supporting reply submission and rereads before closing the editor', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(commentsResponse())
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 77 }), { status: 201, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(commentsResponse());
        vi.stubGlobal('fetch', fetchMock);
        renderControl();

        fireEvent.click(await screen.findByRole('button', { name: 'Reply' }));
        fireEvent.change(screen.getByRole('textbox', { name: 'Reply content' }), { target: { value: 'Real reply' } });
        fireEvent.click(screen.getByRole('button', { name: 'Send reply' }));

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));
        expect(fetchMock.mock.calls[1]?.[0]).toBe('/api/v1/tenants/alpha/sites/17/comments/9/reply');
        expect(fetchMock.mock.calls[1]?.[1]?.method).toBe('POST');
        expect(fetchMock.mock.calls[1]?.[1]?.body).toBe(JSON.stringify({ content: 'Real reply' }));
        await waitFor(() => expect(screen.queryByRole('textbox', { name: 'Reply content' })).not.toBeInTheDocument());
    });

    it('does not automatically retry a failed supporting reply create and keeps the draft available', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(commentsResponse())
            .mockResolvedValueOnce(new Response(JSON.stringify({ message: 'upstream failed' }), { status: 502, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);
        renderControl();

        fireEvent.click(await screen.findByRole('button', { name: 'Reply' }));
        fireEvent.change(screen.getByRole('textbox', { name: 'Reply content' }), { target: { value: 'Keep this draft' } });
        fireEvent.click(screen.getByRole('button', { name: 'Send reply' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('upstream failed');
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(screen.getByRole('textbox', { name: 'Reply content' })).toHaveValue('Keep this draft');
    });
});

describe('commentReplyEndpoint', () => {
    it('accepts only a positive comment id under the same-origin authoritative comments collection', () => {
        expect(commentReplyEndpoint('/api/v1/tenants/alpha/sites/17/comments', 9)).toBe('/api/v1/tenants/alpha/sites/17/comments/9/reply');
        expect(commentReplyEndpoint('/api/v1/tenants/alpha/sites/17/comments?site=17', '10')).toBe('/api/v1/tenants/alpha/sites/17/comments/10/reply');
        expect(commentReplyEndpoint('/api/v1/tenants/alpha/sites/17/comments', 0)).toBeNull();
        expect(commentReplyEndpoint('/api/v1/tenants/alpha/sites/17/comments', '../beta')).toBeNull();
        expect(commentReplyEndpoint('/api/v1/tenants/alpha/sites/17/posts', 9)).toBeNull();
        expect(commentReplyEndpoint('https://evil.example.test/api/v1/tenants/beta/sites/99/comments', 9)).toBeNull();
    });
});
