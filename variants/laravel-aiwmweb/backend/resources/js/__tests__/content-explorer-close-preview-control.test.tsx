import React, { useState } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    CONTENT_EXPLORER_CLOSE_PREVIEW_OPERATION_ID,
    ContentExplorerPreviewPanel,
    type ContentExplorerPreviewRow,
} from '../content-explorer-preview-panel';
import { LocaleProvider } from '../i18n';

// Batch integration verification anchor: five Visible Controls closure, 2026-10-26.

const row: ContentExplorerPreviewRow = {
    id: 17,
    wordpress_id: 451,
    title: 'Authoritative post',
    slug: 'authoritative-post',
    type: 'post',
    status: 'publish',
    modified_at: '2026-10-25T04:00:00Z',
    link: 'https://wordpress.example.test/authoritative-post',
};

function Harness() {
    const [preview, setPreview] = useState<ContentExplorerPreviewRow | null>(row);

    return preview
        ? <ContentExplorerPreviewPanel row={preview} onClose={() => setPreview(null)} />
        : <p>Preview closed</p>;
}

beforeEach(() => {
    window.localStorage.clear();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('AIMW-CONT-FE6EE5740C ContentExplorer ClosePreview', () => {
    it('clears the real local preview without any network request or mutation', () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);

        render(
            <LocaleProvider>
                <Harness />
            </LocaleProvider>,
        );

        expect(screen.getByRole('complementary', { name: 'Content preview' })).toBeInTheDocument();
        const close = screen.getByRole('button', { name: 'Close preview' });
        expect(close).toHaveAttribute('data-canonical-operation', CONTENT_EXPLORER_CLOSE_PREVIEW_OPERATION_ID);
        expect(CONTENT_EXPLORER_CLOSE_PREVIEW_OPERATION_ID).toBe('AIMW-CONT-FE6EE5740C');

        fireEvent.click(close);

        expect(screen.queryByRole('complementary', { name: 'Content preview' })).not.toBeInTheDocument();
        expect(screen.getByText('Preview closed')).toBeInTheDocument();
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('invokes exactly the caller-owned close transition', () => {
        const onClose = vi.fn();

        render(
            <LocaleProvider>
                <ContentExplorerPreviewPanel row={row} onClose={onClose} />
            </LocaleProvider>,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Close preview' }));
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('preserves Arabic close affordance and the same canonical operation', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');

        render(
            <LocaleProvider>
                <ContentExplorerPreviewPanel row={row} onClose={() => undefined} />
            </LocaleProvider>,
        );

        const close = screen.getByRole('button', { name: 'إغلاق المعاينة' });
        expect(close).toHaveAttribute('data-canonical-operation', 'AIMW-CONT-FE6EE5740C');
    });
});
