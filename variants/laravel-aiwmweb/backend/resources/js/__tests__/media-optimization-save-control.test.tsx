import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { LocaleProvider } from '../i18n';
import type { FrontendContext } from '../core';
import { MediaOptimizationEditorControl, MEDIA_SAVE_OPTIMIZATION_OPERATION_ID } from '../media-optimization-editor-control';

const endpoint = '/api/v1/tenants/alpha/sites/7/media';
const row = { id: 5, remote_id: 105, site_id: 7, title: 'Original', alt_text: 'Old alt', mime_type: 'image/jpeg' };
const context = (permissions = ['content.edit']): FrontendContext => ({
    user: { id: 1, name: 'Editor', email: 'editor@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: { media: endpoint },
    actions: {},
});
const show = (ctx = context(), url = endpoint, onReconcile = vi.fn(async () => {})) =>
    render(<LocaleProvider><MediaOptimizationEditorControl context={ctx} endpoint={url} row={row} onReconcile={onReconcile} /></LocaleProvider>);

afterEach(() => vi.unstubAllGlobals());

describe('AIMW-SYNC-425B82511E MediaManager SaveOptimizationClickedAsync', () => {
    it('sends an authorized tenant/site/media PATCH and accepts only an authoritative verified receipt', async () => {
        const fetchMock = vi.fn(async () => new Response(JSON.stringify({ verified: true, remote_id: 105 }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);
        const reconcile = vi.fn(async () => {});
        show(context(), endpoint, reconcile);
        fireEvent.click(screen.getByRole('button', { name: 'Optimize metadata' }));
        fireEvent.change(screen.getByLabelText('Media title'), { target: { value: 'Updated' } });
        const save = screen.getByRole('button', { name: 'Save optimization' });
        expect(save).toHaveAttribute('data-canonical-operation', MEDIA_SAVE_OPTIMIZATION_OPERATION_ID);
        fireEvent.click(save);
        await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('WordPress verified'));
        const [url, init] = (fetchMock.mock.calls as unknown as Array<[string, RequestInit]>)[0];
        expect(url).toBe('/api/v1/tenants/alpha/sites/7/media/5');
        expect(init.method).toBe('PATCH');
        expect(JSON.parse(String(init.body))).toMatchObject({ title: 'Updated', alt_text: 'Old alt' });
        expect(reconcile).toHaveBeenCalledOnce();
    });

    it('does not show save for unauthorized, invalid-tenant or cross-site media endpoints', () => {
        const { rerender } = show(context(['content.view']));
        expect(screen.queryByRole('button', { name: 'Optimize metadata' })).not.toBeInTheDocument();
        rerender(<LocaleProvider><MediaOptimizationEditorControl context={context()} endpoint="/api/v1/tenants/beta/sites/7/media" row={row} onReconcile={vi.fn()} /></LocaleProvider>);
        expect(screen.queryByRole('button', { name: 'Optimize metadata' })).not.toBeInTheDocument();
    });

    it('does not fabricate success for 403 permission denial, 404 foreign tenant, or 409 conflict', async () => {
        for (const status of [403, 404, 409]) {
            vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: `WordPress response ${status}` }), { status, headers: { 'Content-Type': 'application/json' } })));
            const { unmount } = show();
            fireEvent.click(screen.getByRole('button', { name: 'Optimize metadata' }));
            fireEvent.click(screen.getByRole('button', { name: 'Save optimization' }));
            await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent(`WordPress response ${status}`));
            expect(screen.queryByRole('status')).not.toBeInTheDocument();
            unmount();
        }
    });

    it('rejects an unverified server receipt, even after successful HTTP response', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ verified: false, remote_id: 105 }), { status: 200, headers: { 'Content-Type': 'application/json' } })));
        show();
        fireEvent.click(screen.getByRole('button', { name: 'Optimize metadata' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save optimization' }));
        await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('not verified'));
    });
});
