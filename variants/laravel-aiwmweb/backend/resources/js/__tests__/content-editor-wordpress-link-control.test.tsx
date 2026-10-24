import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { LocaleProvider } from '../i18n';
import {
    CONTENT_EDITOR_WORDPRESS_LINK_OPERATION_ID,
    ContentEditorWordPressLinkControl,
    safeContentEditorExternalUrl,
} from '../content-editor-save-control';

const renderControl = (value: unknown) => render(
    <LocaleProvider>
        <ContentEditorWordPressLinkControl value={value} />
    </LocaleProvider>,
);

describe('AIMW-CONT-2C51372C70 Content Editor WordPress link', () => {
    it('renders the authoritative safe WordPress URL in a new tab', () => {
        renderControl('https://alpha.example.test/?p=101');
        const link = screen.getByRole('link', { name: /View on WordPress/i });
        expect(link).toHaveAttribute('href', 'https://alpha.example.test/?p=101');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        expect(link).toHaveAttribute('data-canonical-operation', CONTENT_EDITOR_WORDPRESS_LINK_OPERATION_ID);
    });

    it('fails closed for unsafe, credential-bearing, relative, malformed, blank, and padded URLs', () => {
        for (const value of [
            'javascript:alert(1)',
            'data:text/html,x',
            '/relative',
            'https://user:secret@example.test/post',
            ' not-a-url',
            '',
            ' https://alpha.example.test/post ',
        ]) {
            const { unmount } = renderControl(value);
            expect(screen.queryByRole('link')).not.toBeInTheDocument();
            unmount();
        }
    });
});

describe('safeContentEditorExternalUrl', () => {
    it('allows only absolute credential-free HTTP(S)', () => {
        expect(safeContentEditorExternalUrl('https://alpha.example.test/post')).toBe('https://alpha.example.test/post');
        expect(safeContentEditorExternalUrl('http://alpha.example.test/post')).toBe('http://alpha.example.test/post');
        expect(safeContentEditorExternalUrl('ftp://alpha.example.test/post')).toBeNull();
        expect(safeContentEditorExternalUrl('https://u:p@alpha.example.test/post')).toBeNull();
    });
});
