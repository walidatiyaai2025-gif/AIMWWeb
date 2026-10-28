import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { LocaleProvider } from '../i18n';
import type { FrontendContext } from '../core';
import { MediaOptimizationEditorControl, MEDIA_SAVE_OPTIMIZATION_OPERATION_ID } from '../media-optimization-editor-control';

const endpoint = '/api/v1/tenants/alpha/sites/7/media';
const row = { id: 5, remote_id: 105, site_id: 7, title: 'Stale title', mime_type: 'image/jpeg' };
const authoritative = { remote_id: 105, title: 'Current WordPress title', slug: 'hero-image', alt_text: 'Old alt', caption: 'Existing caption', description: 'Existing description' };
const context = (permissions = ['content.edit']): FrontendContext => ({
    user: { id: 1, name: 'Editor', email: 'editor@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions, connectors: [], capabilities: {}, api: { media: endpoint }, actions: {},
});
function show(ctx = context(), url = endpoint, onReconcile = vi.fn(async () => {})) {
    return render(<LocaleProvider><MediaOptimizationEditorControl context={ctx} endpoint={url} row={row} onReconcile={onReconcile} /></LocaleProvider>);
}
const json = (data: unknown, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
afterEach(() => vi.unstubAllGlobals());

describe('AIMW-SYNC-425B82511E MediaManager SaveOptimizationClickedAsync', () => {
    it('loads live WordPress metadata, saves by tenant/site/media PATCH and requires a verified receipt', async () => {
        const fetchMock = vi.fn(async (_url: string, init: RequestInit) => init.method === 'PATCH'
            ? json({ verified: true, remote_id: 105 })
            : json(authoritative));
        vi.stubGlobal('fetch', fetchMock);
        const reconcile = vi.fn(async () => {});
        show(context(), endpoint, reconcile);
        fireEvent.click(screen.getByRole('button', { name: 'Optimize metadata' }));
        await waitFor(() => expect(screen.getByLabelText('Media title')).toHaveValue('Current WordPress title'));
        expect(screen.getByLabelText('Media caption')).toHaveValue('Existing caption');
        fireEvent.change(screen.getByLabelText('Media title'), { target: { value: 'Updated' } });
        const save = screen.getByRole('button', { name: 'Save optimization' });
        expect(save).toHaveAttribute('data-canonical-operation', MEDIA_SAVE_OPTIMIZATION_OPERATION_ID);
        fireEvent.click(save);
        await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('WordPress verified'));
        const [readUrl] = fetchMock.mock.calls[0];
        const [writeUrl, init] = fetchMock.mock.calls[1];
        expect(readUrl).toBe('/api/v1/tenants/alpha/sites/7/media/5');
        expect(writeUrl).toBe(readUrl);
        expect(init.method).toBe('PATCH');
        expect(JSON.parse(String(init.body))).toMatchObject({ title: 'Updated', alt_text: 'Old alt', caption: 'Existing caption', slug: 'hero-image' });
        expect(reconcile).toHaveBeenCalledOnce();
    });

    it('does not render for unauthorized users or foreign tenant/site endpoints', () => {
        const { rerender } = show(context(['content.view']));
        expect(screen.queryByRole('button', { name: 'Optimize metadata' })).not.toBeInTheDocument();
        rerender(<LocaleProvider><MediaOptimizationEditorControl context={context()} endpoint="/api/v1/tenants/beta/sites/7/media" row={row} onReconcile={vi.fn()} /></LocaleProvider>);
        expect(screen.queryByRole('button', { name: 'Optimize metadata' })).not.toBeInTheDocument();
    });

    it('fails closed on 403 and foreign tenant 404 during authoritative read', async () => {
        for (const status of [403, 404]) {
            vi.stubGlobal('fetch', vi.fn(async () => json({ message: `WordPress response ${status}` }, status)));
            const { unmount } = show();
            fireEvent.click(screen.getByRole('button', { name: 'Optimize metadata' }));
            await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent(`WordPress response ${status}`));
            expect(screen.queryByRole('button', { name: 'Save optimization' })).not.toBeInTheDocument();
            unmount();
        }
    });

    it('refuses stale conflict or unverified WordPress mutation without fabricated success', async () => {
        for (const response of [json({ message: 'Remote edit conflict' }, 409), json({ verified: false, remote_id: 105 })]) {
            vi.stubGlobal('fetch', vi.fn(async (_url: string, init: RequestInit) => init.method === 'PATCH' ? response.clone() : json(authoritative)));
            const { unmount } = show();
            fireEvent.click(screen.getByRole('button', { name: 'Optimize metadata' }));
            await waitFor(() => expect(screen.getByRole('button', { name: 'Save optimization' })).toBeInTheDocument());
            fireEvent.click(screen.getByRole('button', { name: 'Save optimization' }));
            await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
            expect(screen.queryByRole('status')).not.toBeInTheDocument();
            unmount();
        }
    });
});
