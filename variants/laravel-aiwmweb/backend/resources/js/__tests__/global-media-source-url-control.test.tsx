import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    CONTENT_EXPLORER_MEDIA_SOURCE_URL_OPERATION_ID,
    ContentExplorerMediaSourceUrlControl,
} from '../content-explorer-media-source-url-control';
import {
    GLOBAL_MEDIA_SOURCE_URL_OPERATION_ID,
    GlobalMediaSourceUrlOperationBinding,
} from '../global-media-source-url-control';
import { LocaleProvider } from '../i18n';

const renderControl = (value: unknown) => render(
    <LocaleProvider>
        <GlobalMediaSourceUrlOperationBinding value={value}>
            <ContentExplorerMediaSourceUrlControl value={value} />
        </GlobalMediaSourceUrlOperationBinding>
    </LocaleProvider>,
);

describe('AIMW-MEDI-3444B17C11 GlobalMediaExplorer SourceUrl link', () => {
    it('binds the canonical Global Media operation to the existing safe SourceUrl control', () => {
        renderControl('https://alpha.example.test/wp-content/uploads/hero.jpg');

        const link = screen.getByRole('link', { name: 'Open media file' });
        expect(link).toHaveAttribute('href', 'https://alpha.example.test/wp-content/uploads/hero.jpg');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        expect(link).toHaveAttribute(
            'data-canonical-operation',
            CONTENT_EXPLORER_MEDIA_SOURCE_URL_OPERATION_ID,
        );

        const binding = link.closest('[data-canonical-operation="AIMW-MEDI-3444B17C11"]');
        expect(binding).not.toBeNull();
        expect(GLOBAL_MEDIA_SOURCE_URL_OPERATION_ID).toBe('AIMW-MEDI-3444B17C11');
        expect(CONTENT_EXPLORER_MEDIA_SOURCE_URL_OPERATION_ID).toBe('AIMW-CONT-B44DA81C28');
    });

    it('does not materialize the Global Media operation for unsafe or non-authoritative URLs', () => {
        for (const value of [
            null,
            '',
            'javascript:alert(1)',
            'data:text/html,x',
            '/relative/file.jpg',
            'https://user:secret@example.test/file.jpg',
            ' not-a-url',
        ]) {
            const { container, unmount } = renderControl(value);
            expect(screen.queryByRole('link')).not.toBeInTheDocument();
            expect(container.querySelector('[data-canonical-operation="AIMW-MEDI-3444B17C11"]')).toBeNull();
            unmount();
        }
    });
});
