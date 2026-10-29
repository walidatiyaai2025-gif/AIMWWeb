import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    CONTENT_EXPLORER_WORDPRESS_LINK_OPERATION_ID,
    ContentExplorerWordPressLinkControl,
    safeContentExplorerExternalUrl,
} from '../content-explorer-wordpress-link-control';
import { LocaleProvider } from '../i18n';

const renderControl = (value: unknown) => render(
    <LocaleProvider>
        <ContentExplorerWordPressLinkControl value={value} />
    </LocaleProvider>,
);

describe('AIMW-CONT-16D4AD8DC8 Content Explorer WordPress link', () => {
    it('renders the authoritative safe external content URL with source-equivalent new-tab behavior', () => {
        renderControl('https://alpha.example.test/post-71');

        const link = screen.getByRole('link', { name: 'Open content in WordPress' });
        expect(link).toHaveAttribute('href', 'https://alpha.example.test/post-71');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        expect(link).toHaveAttribute('data-canonical-operation', CONTENT_EXPLORER_WORDPRESS_LINK_OPERATION_ID);
    });

    it('fails closed for empty, unsafe, credential-bearing, relative and malformed links', () => {
        for (const value of [
            null,
            '',
            'javascript:alert(1)',
            'data:text/html,x',
            '/relative',
            'https://user:secret@example.test/post',
            ' not-a-url',
        ]) {
            const { unmount } = renderControl(value);
            expect(screen.queryByRole('link')).not.toBeInTheDocument();
            unmount();
        }
    });
});

describe('safeContentExplorerExternalUrl', () => {
    it('allows only absolute credential-free HTTP(S)', () => {
        expect(safeContentExplorerExternalUrl('https://alpha.example.test/post')).toBe('https://alpha.example.test/post');
        expect(safeContentExplorerExternalUrl('http://alpha.example.test/post')).toBe('http://alpha.example.test/post');
        expect(safeContentExplorerExternalUrl('ftp://alpha.example.test/post')).toBeNull();
        expect(safeContentExplorerExternalUrl('https://u:p@alpha.example.test/post')).toBeNull();
    });
});
