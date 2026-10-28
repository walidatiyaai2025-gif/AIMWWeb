import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { LocaleProvider } from '../i18n';
import type { FrontendContext } from '../core';
import {
    inspectMediaBatchFile, MediaBatchClearSelectionControl,
    MEDIA_BATCH_UPLOAD_OPERATION_ID, MEDIA_BATCH_MAX_FILES,
} from '../media-batch-clear-selection-control';

function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Alice', email: 'alice@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view', 'content.view', 'content.edit'],
        connectors: [], capabilities: {},
        api: { media: '/api/v1/tenants/alpha/sites/7/media' },
        actions: {}, active_site: { id: 7 },
    } as FrontendContext;
}

function select(...files: File[]) {
    fireEvent.change(screen.getByLabelText('Choose media files'), { target: { files } });
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    window.localStorage.clear();
});

describe(`${MEDIA_BATCH_UPLOAD_OPERATION_ID} MediaBatchUploadPanel UploadClickedAsync`, () => {
    it('pre-validates the 20-file selection, extension, MIME, and empty files before enqueue', () => {
        expect(MEDIA_BATCH_MAX_FILES).toBe(20);
        expect(inspectMediaBatchFile(new File(['ok'], 'image.png', { type: 'image/png' })).valid).toBe(true);
        expect(inspectMediaBatchFile(new File([], 'empty.png', { type: 'image/png' })).valid).toBe(false);
        expect(inspectMediaBatchFile(new File(['bad'], 'script.exe', { type: 'application/octet-stream' })).valid).toBe(false);
        expect(inspectMediaBatchFile(new File(['bad'], 'image.png', { type: 'application/pdf' })).valid).toBe(false);

        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        render(<LocaleProvider><MediaBatchClearSelectionControl context={context()} /></LocaleProvider>);
        select(new File(['bad'], 'evil.exe', { type: 'application/octet-stream' }));
        const upload = screen.getByRole('button', { name: 'Queue selected files' });
        expect(upload).toHaveAttribute('data-canonical-operation', MEDIA_BATCH_UPLOAD_OPERATION_ID);
        expect(upload).toBeDisabled();
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('queues valid items only, preserves per-item rejected outcome, and never fabricates WordPress success', async () => {
        const fetchSpy = vi.fn().mockResolvedValue(new Response(JSON.stringify({ id: 23, state: 'queued' }), {
            status: 202, headers: { 'content-type': 'application/json' },
        }));
        vi.stubGlobal('fetch', fetchSpy);
        render(<LocaleProvider><MediaBatchClearSelectionControl context={context()} /></LocaleProvider>);
        select(new File(['ok'], 'photo.png', { type: 'image/png' }), new File(['bad'], 'malware.exe'));
        fireEvent.click(screen.getByRole('button', { name: 'Queue selected files' }));

        await waitFor(() => expect(screen.getByText(/1 file\(s\) queued, 0 failed, 1 rejected/)).toBeInTheDocument());
        expect(fetchSpy).toHaveBeenCalledTimes(1);
        expect(fetchSpy.mock.calls[0][0]).toBe('/api/v1/tenants/alpha/sites/7/media');
        expect(fetchSpy.mock.calls[0][1].method).toBe('POST');
        expect(fetchSpy.mock.calls[0][1].body).toBeInstanceOf(FormData);
        expect(screen.getByText(/Queued; remote upload not yet verified/)).toBeInTheDocument();
        expect(screen.getByText(/malware.exe: rejected/)).toBeInTheDocument();
    });

    it('applies title/alt/caption only for one file and re-reads remote transfer outcome', async () => {
        const fetchSpy = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 31, state: 'queued' }), {
                status: 202, headers: { 'content-type': 'application/json' },
            }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 31, state: 'completed' }), {
                status: 200, headers: { 'content-type': 'application/json' },
            }));
        vi.stubGlobal('fetch', fetchSpy);
        render(<LocaleProvider><MediaBatchClearSelectionControl context={context()} /></LocaleProvider>);
        select(new File(['ok'], 'photo.png', { type: 'image/png' }));
        fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Profile photo' } });
        fireEvent.change(screen.getByLabelText('Alt text'), { target: { value: 'A sunny garden' } });
        fireEvent.change(screen.getByLabelText('Caption'), { target: { value: 'Taken today' } });
        fireEvent.click(screen.getByRole('button', { name: 'Queue selected files' }));
        await waitFor(() => expect(fetchSpy).toHaveBeenCalledTimes(1));

        const form = fetchSpy.mock.calls[0][1].body as FormData;
        expect(form.get('title')).toBe('Profile photo');
        expect(form.get('alt_text')).toBe('A sunny garden');
        expect(form.get('caption')).toBe('Taken today');
        fireEvent.click(await screen.findByRole('button', { name: 'Refresh upload status' }));
        await waitFor(() => expect(screen.getByText(/WordPress upload and sync completed/)).toBeInTheDocument());
        expect(fetchSpy.mock.calls[1][0]).toBe('/api/v1/tenants/alpha/sites/7/transfers/31');
    });

    it('reports a rejected request and retains failed files for an explicit retry', async () => {
        const fetchSpy = vi.fn().mockResolvedValue(new Response(JSON.stringify({ message: 'WordPress offline' }), {
            status: 503, headers: { 'content-type': 'application/json' },
        }));
        vi.stubGlobal('fetch', fetchSpy);
        render(<LocaleProvider><MediaBatchClearSelectionControl context={context()} /></LocaleProvider>);
        select(new File(['ok'], 'photo.png', { type: 'image/png' }));
        fireEvent.click(screen.getByRole('button', { name: 'Queue selected files' }));
        await waitFor(() => expect(screen.getByText(/0 file\(s\) queued, 1 failed/)).toBeInTheDocument());
        expect(screen.getByText(/photo.png: failed/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Queue selected files' })).toBeEnabled();
        expect(fetchSpy).toHaveBeenCalledTimes(1);
    });

    it('does not render or send requests for foreign-tenant or unauthorized contexts', () => {
        const limited = context();
        limited.permissions = ['tenant.view', 'sites.view', 'content.view'];
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        const { rerender } = render(<LocaleProvider><MediaBatchClearSelectionControl context={limited} /></LocaleProvider>);
        expect(screen.queryByLabelText('Choose media files')).not.toBeInTheDocument();

        const foreign = context();
        foreign.api.media = '/api/v1/tenants/beta/sites/7/media';
        rerender(<LocaleProvider><MediaBatchClearSelectionControl context={foreign} /></LocaleProvider>);
        expect(screen.queryByLabelText('Choose media files')).not.toBeInTheDocument();
        expect(fetchSpy).not.toHaveBeenCalled();
    });
});
