import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { LocaleProvider } from '../i18n';
import { PAGES_WORDPRESS_LINK_OPERATION_ID, PagesWordPressLinkControl, safeExternalContentUrl } from '../pages-wordpress-link-control';

const renderControl = (value: unknown) => render(
    <LocaleProvider>
        <PagesWordPressLinkControl value={value} />
    </LocaleProvider>,
);

describe('AIMW-CONT-4209A8ADF4 page WordPress link control', () => {
    it('renders the authoritative safe external page URL with source-equivalent new-tab behavior', () => {
        renderControl('https://alpha.example.test/about');
        const link = screen.getByRole('link', { name: 'Open page in WordPress' });
        expect(link).toHaveAttribute('href', 'https://alpha.example.test/about');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        expect(link).toHaveAttribute('data-canonical-operation', PAGES_WORDPRESS_LINK_OPERATION_ID);
    });

    it('fails closed for unsafe, credential-bearing, relative and malformed links', () => {
        for (const value of ['javascript:alert(1)', 'data:text/html,x', '/relative', 'https://user:secret@example.test/page', ' not-a-url']) {
            const { unmount } = renderControl(value);
            expect(screen.queryByRole('link')).not.toBeInTheDocument();
            unmount();
        }
    });
});

describe('safeExternalContentUrl', () => {
    it('allows only absolute credential-free HTTP(S)', () => {
        expect(safeExternalContentUrl('https://alpha.example.test/page')).toBe('https://alpha.example.test/page');
        expect(safeExternalContentUrl('http://alpha.example.test/page')).toBe('http://alpha.example.test/page');
        expect(safeExternalContentUrl('ftp://alpha.example.test/page')).toBeNull();
        expect(safeExternalContentUrl('https://u:p@alpha.example.test/page')).toBeNull();
    });
});
