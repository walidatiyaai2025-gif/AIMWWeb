import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { LocaleProvider } from '../i18n';
import {
    GLOBAL_MEDIA_INTERPOLATED_LINKS_OPERATION_ID,
    GlobalMediaInterpolatedLinksControl,
    globalMediaInterpolatedDestinations,
} from '../media-interpolated-links-control';

function renderControl(siteId: unknown = 7) {
    return render(
        <LocaleProvider>
            <GlobalMediaInterpolatedLinksControl tenantSlug="alpha" siteId={siteId} />
        </LocaleProvider>,
    );
}

afterEach(() => cleanup());

describe('AIMW-MEDI-727FAEEB01 Global Media interpolated links', () => {
    it('renders tenant-bound manage and Explorer destinations from the authoritative site id', () => {
        renderControl();

        const manage = screen.getByRole('link', { name: 'Manage & optimize' });
        expect(manage).toHaveAttribute('href', '/tenants/alpha/module/media?site=7');
        expect(manage).toHaveAttribute('data-source-control', 'manage-media');
        expect(manage).toHaveAttribute('data-canonical-operation', GLOBAL_MEDIA_INTERPOLATED_LINKS_OPERATION_ID);

        const explorer = screen.getByRole('link', { name: 'Explorer' });
        expect(explorer).toHaveAttribute('href', '/tenants/alpha/explorer?site=7');
        expect(explorer).toHaveAttribute('data-source-control', 'site-explorer');
        expect(explorer).toHaveAttribute('data-canonical-operation', GLOBAL_MEDIA_INTERPOLATED_LINKS_OPERATION_ID);
        expect(GLOBAL_MEDIA_INTERPOLATED_LINKS_OPERATION_ID).toBe('AIMW-MEDI-727FAEEB01');
    });

    it('fails closed for missing or malformed authoritative site identifiers', () => {
        for (const siteId of [null, '', 0, -1, 7.5, '../7', ' 7', '7/../../beta']) {
            const { unmount } = renderControl(siteId);
            expect(screen.queryByRole('link')).not.toBeInTheDocument();
            unmount();
        }
    });
});

describe('globalMediaInterpolatedDestinations', () => {
    it('derives only canonical tenant-qualified destinations', () => {
        expect(globalMediaInterpolatedDestinations('alpha', 9)).toEqual({
            manage: '/tenants/alpha/module/media?site=9',
            explorer: '/tenants/alpha/explorer?site=9',
        });
        expect(globalMediaInterpolatedDestinations('', 9)).toBeNull();
        expect(globalMediaInterpolatedDestinations('alpha', '09')).toBeNull();
    });
});
