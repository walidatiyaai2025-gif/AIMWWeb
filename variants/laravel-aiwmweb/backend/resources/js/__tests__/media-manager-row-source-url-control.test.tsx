import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { ContentExplorerMediaSourceUrlControl } from '../content-explorer-media-source-url-control';
import {
    MEDIA_MANAGER_ROW_SOURCE_URL_OPERATION_ID,
    MediaManagerRowSourceUrlControl,
} from '../media-manager-row-source-url-control';
import { LocaleProvider } from '../i18n';

afterEach(() => cleanup());

function renderControl(value: unknown) {
    return render(
        <LocaleProvider>
            <MediaManagerRowSourceUrlControl value={value}>
                <ContentExplorerMediaSourceUrlControl value={value} />
            </MediaManagerRowSourceUrlControl>
        </LocaleProvider>,
    );
}

describe('AIMW-MEDI-34E7DCA832 MediaManager row SourceUrl control', () => {
    it('marks the existing authoritative hardened Open file action without duplicating it', () => {
        renderControl('https://wp.example.test/wp-content/uploads/image.jpg');

        const wrapper = screen.getByText('Open file ↗').closest('[data-source-control="media-manager-row-source-url"]');
        expect(wrapper).toHaveAttribute('data-canonical-operation', MEDIA_MANAGER_ROW_SOURCE_URL_OPERATION_ID);
        expect(MEDIA_MANAGER_ROW_SOURCE_URL_OPERATION_ID).toBe('AIMW-MEDI-34E7DCA832');

        const links = screen.getAllByRole('link', { name: 'Open media file' });
        expect(links).toHaveLength(1);
        expect(links[0]).toHaveAttribute('href', 'https://wp.example.test/wp-content/uploads/image.jpg');
        expect(links[0]).toHaveAttribute('target', '_blank');
        expect(links[0]).toHaveAttribute('rel', 'noopener noreferrer');
        expect(wrapper).not.toHaveAttribute('data-method');
    });

    it.each([
        ['javascript:alert(1)'],
        ['data:text/html,unsafe'],
        ['https://user:secret@wp.example.test/file.jpg'],
        [' https://wp.example.test/file.jpg'],
        [''],
        [null],
    ])('fails closed for unsafe or non-authoritative source URL %j', (value) => {
        renderControl(value);
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(screen.queryByText('Open file ↗')).not.toBeInTheDocument();
    });
});
