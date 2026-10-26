import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { LocaleProvider } from '../i18n';
import {
    PAGES_INTERPOLATED_LINKS_OPERATION_ID,
    PagesInterpolatedLinksControl,
    pagesInterpolatedDestinations,
} from '../pages-interpolated-links-control';

function renderControl(siteId: unknown = 7, wordpressId: unknown = 71, siteName: unknown = 'Alpha Site', tenantSlug = 'alpha') {
    return render(
        <LocaleProvider>
            <PagesInterpolatedLinksControl
                tenantSlug={tenantSlug}
                siteId={siteId}
                wordpressId={wordpressId}
                siteName={siteName}
            />
        </LocaleProvider>,
    );
}

afterEach(() => cleanup());

describe('AIMW-CONT-BB31987A53 Global Pages interpolated links', () => {
    it('renders the authoritative site, page editor and Explorer destinations', () => {
        renderControl();

        const site = screen.getByRole('link', { name: 'Alpha Site' });
        expect(site).toHaveAttribute('href', '/tenants/alpha/sites/7');
        expect(site).toHaveAttribute('data-source-control', 'site-details');
        expect(site).toHaveAttribute('data-canonical-operation', PAGES_INTERPOLATED_LINKS_OPERATION_ID);

        const edit = screen.getByRole('link', { name: 'Edit' });
        expect(edit).toHaveAttribute('href', '/tenants/alpha/sites/7/content/page/71/edit');
        expect(edit).toHaveAttribute('data-source-control', 'page-editor');

        const explorer = screen.getByRole('link', { name: 'Explorer' });
        expect(explorer).toHaveAttribute('href', '/tenants/alpha/explorer?site=7');
        expect(explorer).toHaveAttribute('data-source-control', 'site-explorer');
    });

    it('fails closed when authoritative identifiers are missing or malformed', () => {
        for (const [siteId, wordpressId] of [
            [0, 71],
            ['../7', 71],
            [7, 0],
            [7, '../71'],
            [7.5, 71],
        ] as Array<[unknown, unknown]>) {
            const { unmount } = renderControl(siteId, wordpressId);
            expect(screen.queryByRole('link')).not.toBeInTheDocument();
            unmount();
        }
    });

    it('keeps a foreign tenant path fragment encoded behind the server 404 boundary', () => {
        renderControl(7, 71, 'Alpha Site', 'alpha/../foreign');
        const site = screen.getByRole('link', { name: 'Alpha Site' });
        expect(site).toHaveAttribute('href', '/tenants/alpha%2F..%2Fforeign/sites/7');
        expect(site.getAttribute('href')).not.toBe('/tenants/foreign/sites/7');
    });

    it('uses a deterministic fallback label without synthesizing identifiers', () => {
        renderControl('7', '71', '   ');
        expect(screen.getByRole('link', { name: 'Site' })).toHaveAttribute('href', '/tenants/alpha/sites/7');
    });
});

describe('pagesInterpolatedDestinations', () => {
    it('derives only tenant-bound internal destinations from positive integer ids', () => {
        expect(PAGES_INTERPOLATED_LINKS_OPERATION_ID).toBe('AIMW-CONT-BB31987A53');
        expect(pagesInterpolatedDestinations('alpha', 9, 101)).toEqual({
            site: '/tenants/alpha/sites/9',
            editor: '/tenants/alpha/sites/9/content/page/101/edit',
            explorer: '/tenants/alpha/explorer?site=9',
        });
        expect(pagesInterpolatedDestinations('', 9, 101)).toBeNull();
        expect(pagesInterpolatedDestinations('alpha', ' 9', 101)).toBeNull();
        expect(pagesInterpolatedDestinations('alpha', 9, '101/../../beta')).toBeNull();
    });
});
