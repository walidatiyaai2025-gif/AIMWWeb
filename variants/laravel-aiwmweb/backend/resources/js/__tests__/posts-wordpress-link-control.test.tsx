import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { LocaleProvider } from '../i18n';
import { POSTS_WORDPRESS_LINK_OPERATION_ID, PostsWordPressLinkControl, safeExternalPostUrl } from '../posts-wordpress-link-control';

const renderControl = (value: unknown) => render(
    <LocaleProvider>
        <PostsWordPressLinkControl value={value} />
    </LocaleProvider>,
);

describe('AIMW-CONT-DF8B12B6DE post WordPress link control', () => {
    it('renders the authoritative safe external post URL with source-equivalent new-tab behavior', () => {
        renderControl('https://alpha.example.test/post-71');
        const link = screen.getByRole('link', { name: 'Open post in WordPress' });
        expect(link).toHaveAttribute('href', 'https://alpha.example.test/post-71');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        expect(link).toHaveAttribute('data-canonical-operation', POSTS_WORDPRESS_LINK_OPERATION_ID);
    });

    it('fails closed for unsafe, credential-bearing, relative and malformed links', () => {
        for (const value of ['javascript:alert(1)', 'data:text/html,x', '/relative', 'https://user:secret@example.test/post', ' not-a-url']) {
            const { unmount } = renderControl(value);
            expect(screen.queryByRole('link')).not.toBeInTheDocument();
            unmount();
        }
    });
});

describe('safeExternalPostUrl', () => {
    it('allows only absolute credential-free HTTP(S)', () => {
        expect(safeExternalPostUrl('https://alpha.example.test/post')).toBe('https://alpha.example.test/post');
        expect(safeExternalPostUrl('http://alpha.example.test/post')).toBe('http://alpha.example.test/post');
        expect(safeExternalPostUrl('ftp://alpha.example.test/post')).toBeNull();
        expect(safeExternalPostUrl('https://u:p@alpha.example.test/post')).toBeNull();
    });
});
