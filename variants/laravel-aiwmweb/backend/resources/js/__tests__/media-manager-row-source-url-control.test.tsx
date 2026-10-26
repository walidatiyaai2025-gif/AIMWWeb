import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { LocaleProvider } from '../i18n';
import {
    MEDIA_MANAGER_ROW_SOURCE_URL_OPERATION_ID,
    MediaManagerRowSourceUrlControl,
} from '../media-manager-row-source-url-control';

afterEach(() => cleanup());

function renderControl(value: unknown) {
    return render(
        <LocaleProvider>
            <MediaManagerRowSourceUrlControl value={value} />
        </LocaleProvider>,
    );
}

describe('AIMW-MEDI-34E7DCA832 MediaManager row SourceUrl control', () => {
    it('renders the authoritative WordPress media URL as a read-only hardened external link', () => {
        renderControl('https://wp.example.test/wp-content/uploads/image.jpg');

        const link = screen.getByRole('link', { name: 'Open media file' });
        expect(link).toHaveAttribute('href', 'https://wp.example.test/wp-content/uploads/image.jpg');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        expect(link).toHaveAttribute('data-canonical-operation', MEDIA_MANAGER_ROW_SOURCE_URL_OPERATION_ID);
        expect(MEDIA_MANAGER_ROW_SOURCE_URL_OPERATION_ID).toBe('AIMW-MEDI-34E7DCA832');
        expect(link).not.toHaveAttribute('data-method');
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
    });
});
