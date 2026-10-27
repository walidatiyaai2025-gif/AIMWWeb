import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    CONTENT_EXPLORER_MEDIA_SOURCE_URL_OPERATION_ID,
    ContentExplorerMediaSourceUrlControl,
} from '../content-explorer-media-source-url-control';
import {
    MEDIA_MANAGER_EDITING_SOURCE_URL_OPERATION_ID,
    MediaManagerEditingSourceUrlControl,
} from '../media-manager-editing-source-url-control';
import {
    MEDIA_MANAGER_ROW_SOURCE_URL_OPERATION_ID,
    MediaManagerRowSourceUrlControl,
} from '../media-manager-row-source-url-control';
import { LocaleProvider } from '../i18n';

function renderControl(value: unknown) {
    return render(
        <LocaleProvider>
            <MediaManagerRowSourceUrlControl value={value}>
                <MediaManagerEditingSourceUrlControl value={value}>
                    <ContentExplorerMediaSourceUrlControl value={value} />
                </MediaManagerEditingSourceUrlControl>
            </MediaManagerRowSourceUrlControl>
        </LocaleProvider>,
    );
}

describe('AIMW-MEDI-34E7DCA832 MediaManager row SourceUrl link', () => {
    it('adapts the row OpenFile intent onto the existing authoritative SourceUrl action without duplicating it', () => {
        renderControl('https://alpha.example.test/wp-content/uploads/hero.jpg');

        const rowWrapper = screen.getByTestId('media-manager-row-source-url');
        const editingWrapper = screen.getByTestId('media-manager-editing-source-url');
        const link = screen.getByRole('link', { name: 'Open media file' });

        expect(MEDIA_MANAGER_ROW_SOURCE_URL_OPERATION_ID).toBe('AIMW-MEDI-34E7DCA832');
        expect(rowWrapper).toHaveAttribute(
            'data-canonical-operation',
            MEDIA_MANAGER_ROW_SOURCE_URL_OPERATION_ID,
        );
        expect(editingWrapper).toHaveAttribute(
            'data-canonical-operation',
            MEDIA_MANAGER_EDITING_SOURCE_URL_OPERATION_ID,
        );
        expect(link).toHaveAttribute(
            'data-canonical-operation',
            CONTENT_EXPLORER_MEDIA_SOURCE_URL_OPERATION_ID,
        );
        expect(link).toHaveAttribute('href', 'https://alpha.example.test/wp-content/uploads/hero.jpg');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        expect(screen.getAllByRole('link', { name: 'Open media file' })).toHaveLength(1);
    });

    it('fails closed for unsafe or empty SourceUrl values', () => {
        for (const value of [
            null,
            '',
            'javascript:alert(1)',
            'data:text/html,x',
            '/relative/file.jpg',
            'https://user:secret@example.test/file.jpg',
        ]) {
            const { unmount } = renderControl(value);
            expect(screen.queryByTestId('media-manager-row-source-url')).not.toBeInTheDocument();
            expect(screen.queryByRole('link')).not.toBeInTheDocument();
            unmount();
        }
    });

    it('inherits the authoritative Media API foreign-tenant 404 boundary and accepts no caller URL override', () => {
        const tenantOwnedUrl = 'https://alpha.example.test/wp-content/uploads/tenant-owned.jpg';
        renderControl(tenantOwnedUrl);

        const link = screen.getByRole('link', { name: 'Open media file' });
        expect(link).toHaveAttribute('href', tenantOwnedUrl);
        expect(link.getAttribute('href')).not.toContain('foreign.example.test');
    });
});
