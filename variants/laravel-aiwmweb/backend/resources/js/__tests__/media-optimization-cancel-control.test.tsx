import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { LocaleProvider } from '../i18n';
import { MediaOptimizationEditorControl, MEDIA_CANCEL_OPTIMIZATION_OPERATION_ID } from '../media-optimization-editor-control';
import type { FrontendContext } from '../core';

const endpoint = '/api/v1/tenants/alpha/sites/7/media';
const row = { id: 5, remote_id: 105, site_id: 7, title: 'Stale', mime_type: 'image/jpeg' };
const context: FrontendContext = {
    user: { id: 1, name: 'Editor', email: 'editor@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['content.edit'],
    connectors: [], capabilities: {},
    api: { media: endpoint }, actions: {},
};
const remote = { remote_id: 105, modified_gmt: '2026-10-28T01:00:00', title: 'Current', slug: 'current', alt_text: 'Actual alt', caption: '', description: '' };
function renderControl(ctx = context) {
    return render(<LocaleProvider><MediaOptimizationEditorControl context={ctx} endpoint={endpoint} row={row} onReconcile={vi.fn(async () => {})} /></LocaleProvider>);
}
afterEach(() => vi.unstubAllGlobals());

describe('AIMW-SYNC-4B986B2651 MediaManager CancelOptimizationClickedAsync', () => {
    it('drops unsaved changes locally and reloads authoritative WordPress fields next time', async () => {
        let reads = 0;
        const fetchMock = vi.fn(async () => {
            reads++;
            return new Response(JSON.stringify(reads === 1 ? remote : { ...remote, title: 'Updated on WordPress', alt_text: 'Current remote alt', caption: 'Published caption' }), {
                status: 200, headers: { 'Content-Type': 'application/json' },
            });
        });
        const onReconcile = vi.fn(async () => {});
        vi.stubGlobal('fetch', fetchMock);
        render(<LocaleProvider><MediaOptimizationEditorControl context={context} endpoint={endpoint} row={row} onReconcile={onReconcile} /></LocaleProvider>);
        fireEvent.click(screen.getByRole('button', { name: 'Optimize metadata' }));
        await waitFor(() => expect(screen.getByLabelText('Media title')).toHaveValue('Current'));
        fireEvent.change(screen.getByLabelText('Media title'), { target: { value: 'Uncommitted title' } });
        fireEvent.change(screen.getByLabelText('Media alt text'), { target: { value: 'Uncommitted alt text' } });
        expect(screen.getByLabelText('Media alt text')).toHaveValue('Uncommitted alt text');
        fireEvent.change(screen.getByLabelText('Media caption'), { target: { value: 'Draft caption' } });
        expect(screen.getByLabelText('Media caption')).toHaveValue('Draft caption');
        const cancel = screen.getByRole('button', { name: 'Cancel optimization' });
        expect(cancel).toHaveAttribute('data-canonical-operation', MEDIA_CANCEL_OPTIMIZATION_OPERATION_ID);
        fireEvent.click(cancel);
        expect(screen.queryByLabelText('Media title')).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls.every((call) => (call as unknown as [unknown, RequestInit])[1]?.method !== 'PATCH')).toBe(true);
        expect(onReconcile).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Optimize metadata' }));
        await waitFor(() => expect(screen.getByLabelText('Media title')).toHaveValue('Updated on WordPress'));
        expect(screen.getByLabelText('Media alt text')).toHaveValue('Current remote alt');
        expect(screen.getByLabelText('Media caption')).toHaveValue('Published caption');
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(onReconcile).not.toHaveBeenCalled();
    });

    it('never exposes a cancel control for unauthorized or foreign tenant media', () => {
        const { rerender } = renderControl({ ...context, permissions: ['content.view'] });
        expect(screen.queryByRole('button', { name: 'Cancel optimization' })).not.toBeInTheDocument();
        rerender(<LocaleProvider><MediaOptimizationEditorControl context={context} endpoint="/api/v1/tenants/beta/sites/7/media" row={row} onReconcile={vi.fn()} /></LocaleProvider>);
        expect(screen.queryByRole('button', { name: 'Optimize metadata' })).not.toBeInTheDocument();
    });

    it('preserves foreign tenant 404 and does not render an editable draft or fake cancellation', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'Foreign tenant media not found' }), { status: 404, headers: { 'Content-Type': 'application/json' } })));
        renderControl();
        fireEvent.click(screen.getByRole('button', { name: 'Optimize metadata' }));
        await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Foreign tenant media not found'));
        expect(screen.queryByRole('button', { name: 'Cancel optimization' })).not.toBeInTheDocument();
    });
});
