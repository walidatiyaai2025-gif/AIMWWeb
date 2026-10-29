import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { ApiError } from '../core';
import {
    MEDIA_BACK_TO_SITES_OPERATION_ID,
    MediaBackToSitesControl,
    isAuthoritativeMediaNotFound,
} from '../media-back-to-sites-control';
import { LocaleProvider } from '../i18n';

function renderControl(error: unknown, tenantSlug = 'alpha') {
    return render(
        <MemoryRouter>
            <LocaleProvider>
                <MediaBackToSitesControl tenantSlug={tenantSlug} error={error} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

describe('AIMW-MEDI-34B5C789D2 MediaManager Back to sites', () => {
    it('renders the tenant-qualified recovery action only for authoritative 404', () => {
        renderControl(new ApiError('Site not found.', 404, 'not_found'));

        const link = screen.getByRole('link', { name: /back to sites/i });
        expect(MEDIA_BACK_TO_SITES_OPERATION_ID).toBe('AIMW-MEDI-34B5C789D2');
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites');
        expect(link).toHaveAttribute('data-testid', 'media-back-to-sites');
        expect(link.closest('nav')).toHaveAttribute(
            'data-canonical-operation',
            MEDIA_BACK_TO_SITES_OPERATION_ID,
        );
        expect(isAuthoritativeMediaNotFound(new ApiError('Missing.', 404, 'missing'))).toBe(true);
    });

    it('does not manufacture the source recovery action for generic server failure', () => {
        renderControl(new ApiError('Unavailable.', 500, 'server_error'));

        expect(screen.queryByRole('link', { name: /back to sites/i })).not.toBeInTheDocument();
        expect(isAuthoritativeMediaNotFound(new ApiError('Unavailable.', 500, 'server_error'))).toBe(false);
    });

    it('does not render on successful or non-error media state', () => {
        renderControl(undefined);

        expect(screen.queryByRole('link', { name: /back to sites/i })).not.toBeInTheDocument();
    });

    it('derives and encodes the destination only from the active tenant slug', () => {
        renderControl(new ApiError('Site not found.', 404, 'not_found'), 'tenant one');

        expect(screen.getByRole('link', { name: /back to sites/i }))
            .toHaveAttribute('href', '/tenants/tenant%20one/sites');
    });

    it('fails closed for an invalid tenant slug instead of creating a caller-controlled path', () => {
        renderControl(new ApiError('Site not found.', 404, 'not_found'), '../foreign');

        expect(screen.queryByRole('link', { name: /back to sites/i })).not.toBeInTheDocument();
    });
});
