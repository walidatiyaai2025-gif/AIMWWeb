import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import {
    GLOBAL_SYNCHRONIZATION_CONFLICT_EDITOR_LINK_OPERATION_ID,
    globalSynchronizationConflictEditorHref,
    GlobalSynchronizationReviewConflictsControl,
} from '../global-synchronization-review-conflicts-control';
import { LocaleProvider } from '../i18n';

type SiteContext = FrontendContext & { active_site?: { id: number; name: string } | null };

function context(changes: Partial<SiteContext> = {}): SiteContext {
    return {
        user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view', 'content.view', 'content.edit'],
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
        active_site: { id: 7, name: 'Alpha Site' },
        ...changes,
    };
}

const conflict = {
    content_type: 'post',
    wordpress_id: 101,
    kind: 'RemoteUpdated',
    local: { title: 'Local title', slug: 'local', status: 'draft', link: '', rendered_content: '', rendered_excerpt: '' },
    remote: { title: 'Remote title', slug: 'remote', status: 'publish', link: '', rendered_content: '', rendered_excerpt: '' },
};
const review = {
    operation_id: 'AIMW-BILL-5887A977D7',
    has_baseline: true,
    remote_additions: 0,
    remote_updates: 1,
    remote_deletions: 0,
    has_conflicts: true,
    conflicts: [conflict],
};

function renderControl(value: SiteContext = context()) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <MemoryRouter>
            <QueryClientProvider client={client}>
                <LocaleProvider>
                    <GlobalSynchronizationReviewConflictsControl context={value} />
                </LocaleProvider>
            </QueryClientProvider>
        </MemoryRouter>,
    );
}

afterEach(() => vi.unstubAllGlobals());

describe('AIMW-SYNC-0239B0E6A3 GlobalSynchronizationWorkspace editor link', () => {
    it('opens the real tenant/site/content editor only from a verified live conflict', async () => {
        expect(GLOBAL_SYNCHRONIZATION_CONFLICT_EDITOR_LINK_OPERATION_ID).toBe('AIMW-SYNC-0239B0E6A3');
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify(review), {
            status: 200, headers: { 'content-type': 'application/json' },
        }));
        vi.stubGlobal('fetch', fetchMock);
        renderControl();
        expect(screen.queryByRole('link', { name: 'Open editor to merge' })).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Review remote changes' }));
        const link = await screen.findByRole('link', { name: 'Open editor to merge' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites/7/content/post/101/edit');
        expect(link).toHaveAttribute('data-canonical-operation', GLOBAL_SYNCHRONIZATION_CONFLICT_EDITOR_LINK_OPERATION_ID);
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/tenants/alpha/sites/7/sync/review-conflicts');
    });

    it('never offers an editor for remotely deleted conflicts', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
            ...review,
            conflicts: [{ ...conflict, kind: 'RemoteDeleted', remote: null }],
        }), { status: 200, headers: { 'content-type': 'application/json' } })));
        renderControl();
        fireEvent.click(screen.getByRole('button', { name: 'Review remote changes' }));
        expect(await screen.findByText('Deleted in WordPress')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Open editor to merge' })).toBeNull();
    });

    it('fails closed for cross-tenant and malformed direct identifiers and unauthorized actors (404)', () => {
        const alpha = context();
        expect(globalSynchronizationConflictEditorHref(alpha, 99, 'post', 101, 'RemoteUpdated')).toBeNull();
        expect(globalSynchronizationConflictEditorHref(alpha, 7, 'user', 101, 'RemoteUpdated')).toBeNull();
        expect(globalSynchronizationConflictEditorHref(alpha, 7, 'post', 0, 'RemoteUpdated')).toBeNull();
        expect(globalSynchronizationConflictEditorHref(alpha, 7, 'post', 101, 'RemoteDeleted')).toBeNull();
        expect(globalSynchronizationConflictEditorHref(context({ tenant: { slug: 'beta/foreign', name: 'Beta' } }), 7, 'post', 101, 'RemoteUpdated')).toBeNull();
        expect(globalSynchronizationConflictEditorHref(context({ permissions: ['tenant.view', 'sites.view', 'content.view'] }), 7, 'post', 101, 'RemoteUpdated')).toBeNull();
        expect(globalSynchronizationConflictEditorHref(context({ active_site: null }), 7, 'post', 101, 'RemoteUpdated')).toBeNull();
    });

    it('does not render the link on unverified or failed authoritative reviews', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
            ...review,
            operation_id: 'wrong-operation',
        }), { status: 200, headers: { 'content-type': 'application/json' } })));
        renderControl();
        fireEvent.click(screen.getByRole('button', { name: 'Review remote changes' }));
        expect(await screen.findByText('Unexpected conflict review operation identity.')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Open editor to merge' })).toBeNull();
    });
});
