import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    CONTENT_EXPLORER_MEDIA_SOURCE_URL_OPERATION_ID,
    GLOBAL_MEDIA_SOURCE_URL_OPERATION_ID,
    ContentExplorerMediaSourceUrlControl,
    safeContentExplorerMediaSourceUrl,
} from '../content-explorer-media-source-url-control';
import { LocaleProvider } from '../i18n';

const renderControl = (value: unknown) => render(
    <LocaleProvider>
        <ContentExplorerMediaSourceUrlControl value={value} />
    </LocaleProvider>,
);

describe('AIMW-CONT-B44DA81C28 Content Explorer media SourceUrl link', () => {
    it('renders the authoritative persisted SourceUrl with source-equivalent new-tab behavior', () => {
        renderControl('https://alpha.example.test/wp-content/uploads/hero.jpg');

        const link = screen.getByRole('link', { name: 'Open media file' });
        expect(link).toHaveAttribute('href', 'https://alpha.example.test/wp-content/uploads/hero.jpg');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        expect(link).toHaveAttribute(
            'data-canonical-operation',
            CONTENT_EXPLORER_MEDIA_SOURCE_URL_OPERATION_ID,
        );
        expect(CONTENT_EXPLORER_MEDIA_SOURCE_URL_OPERATION_ID).toBe('AIMW-CONT-B44DA81C28');
        expect(link).toHaveAttribute('data-canonical-global-media-operation', GLOBAL_MEDIA_SOURCE_URL_OPERATION_ID);
        expect(GLOBAL_MEDIA_SOURCE_URL_OPERATION_ID).toBe('AIMW-MEDI-3444B17C11');
    });

    it('fails closed for empty, unsafe, credential-bearing, relative and malformed URLs', () => {
        for (const value of [
            null,
            '',
            'javascript:alert(1)',
            'data:text/html,x',
            '/relative/file.jpg',
            'https://user:secret@example.test/file.jpg',
            ' not-a-url',
        ]) {
            const { unmount } = renderControl(value);
            expect(screen.queryByRole('link')).not.toBeInTheDocument();
            unmount();
        }
    });
});

describe('safeContentExplorerMediaSourceUrl', () => {
    it('allows only absolute credential-free HTTP(S)', () => {
        expect(safeContentExplorerMediaSourceUrl('https://alpha.example.test/file.jpg'))
            .toBe('https://alpha.example.test/file.jpg');
        expect(safeContentExplorerMediaSourceUrl('http://alpha.example.test/file.jpg'))
            .toBe('http://alpha.example.test/file.jpg');
        expect(safeContentExplorerMediaSourceUrl('ftp://alpha.example.test/file.jpg')).toBeNull();
        expect(safeContentExplorerMediaSourceUrl('https://u:p@alpha.example.test/file.jpg')).toBeNull();
    });
});
