import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { LocaleProvider } from '../i18n';
import type { FrontendContext } from '../core';
import {
    authorizedMediaUploadEndpoint, MediaBatchClearSelectionControl,
    MEDIA_BATCH_CLEAR_SELECTION_OPERATION_ID,
} from '../media-batch-clear-selection-control';

function context(permissions = ['tenant.view', 'sites.view', 'content.view', 'content.edit']): FrontendContext {
    return {
        user: { id: 1, name: 'Alice', email: 'alice@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions,
        connectors: [],
        capabilities: {},
        api: { media: '/api/v1/tenants/alpha/sites/7/media' },
        actions: {},
        active_site: { id: 7 },
    } as FrontendContext;
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    window.localStorage.clear();
});

function choose(name = 'one.png') {
    const input = screen.getByLabelText('Choose media files') as HTMLInputElement;
    fireEvent.change(input, { target: { files: [new File(['image'], name, { type: 'image/png' })] } });
    return input;
}

describe(`${MEDIA_BATCH_CLEAR_SELECTION_OPERATION_ID} MediaBatchUploadPanel ClearClickedAsync`, () => {
    it('clears a real selected upload batch without any network or server mutation', () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        render(<LocaleProvider><MediaBatchClearSelectionControl context={context()} /></LocaleProvider>);

        choose();
        expect(screen.getByText('one.png')).toBeInTheDocument();
        const cancel = screen.getByRole('button', { name: 'Cancel' });
        expect(cancel).toHaveAttribute('data-canonical-operation', MEDIA_BATCH_CLEAR_SELECTION_OPERATION_ID);
        fireEvent.click(cancel);

        expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();
        expect(screen.queryByText('one.png')).not.toBeInTheDocument();
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('enqueues only through the actual scoped API and never claims remote upload completion', async () => {
        const fetchSpy = vi.fn().mockResolvedValue(new Response(JSON.stringify({ id: 99, state: 'queued' }), {
            status: 202, headers: { 'content-type': 'application/json' },
        }));
        vi.stubGlobal('fetch', fetchSpy);
        render(<LocaleProvider><MediaBatchClearSelectionControl context={context()} /></LocaleProvider>);
        choose('upload.png');
        fireEvent.click(screen.getByRole('button', { name: 'Queue selected files' }));

        await waitFor(() => expect(fetchSpy).toHaveBeenCalledTimes(1));
        expect(fetchSpy.mock.calls[0][0]).toBe('/api/v1/tenants/alpha/sites/7/media');
        expect(fetchSpy.mock.calls[0][1].method).toBe('POST');
        expect(fetchSpy.mock.calls[0][1].body).toBeInstanceOf(FormData);
        await waitFor(() => expect(screen.getByText(/file\(s\) queued/)).toBeInTheDocument());
        expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();
    });

    it('fails closed for viewers, absent site, and foreign-site endpoint', () => {
        const limited = context(['tenant.view', 'sites.view', 'content.view']);
        expect(authorizedMediaUploadEndpoint(limited)).toBeNull();

        const foreign = context() as FrontendContext & { active_site: { id: number } };
        foreign.api.media = '/api/v1/tenants/beta/sites/7/media';
        expect(authorizedMediaUploadEndpoint(foreign)).toBeNull();
        foreign.api.media = '/api/v1/tenants/alpha/sites/8/media';
        expect(authorizedMediaUploadEndpoint(foreign)).toBeNull();
        foreign.active_site.id = 0;
        expect(authorizedMediaUploadEndpoint(foreign)).toBeNull();
    });

    it('supports Arabic cancel with the same local-only semantics', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        render(<LocaleProvider><MediaBatchClearSelectionControl context={context()} /></LocaleProvider>);
        const input = screen.getByLabelText('اختيار ملفات الوسائط');
        fireEvent.change(input, { target: { files: [new File(['x'], 'arabic.png')] } });
        fireEvent.click(screen.getByRole('button', { name: 'إلغاء' }));
        expect(screen.queryByText('arabic.png')).not.toBeInTheDocument();
        expect(fetchSpy).not.toHaveBeenCalled();
    });
});
