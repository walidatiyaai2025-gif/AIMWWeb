import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { COMMENTS_BACK_TO_SITES_OPERATION_ID, CommentsBackToSitesControl } from '../comments-back-to-sites-control';
import { type FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

function context(slug = 'alpha'): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug, name: 'Active tenant' },
        tenants: [{ slug, name: 'Active tenant' }],
        permissions: ['tenant.view', 'sites.view', 'content.view'],
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
    };
}

describe('canonical Comments back-to-sites control', () => {
    it('renders the exact canonical navigation control for the active tenant', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <CommentsBackToSitesControl context={context()} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        const link = screen.getByRole('link', { name: /back to sites/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites');
        expect(link.closest('nav')).toHaveAttribute('data-canonical-operation', COMMENTS_BACK_TO_SITES_OPERATION_ID);
        expect(COMMENTS_BACK_TO_SITES_OPERATION_ID).toBe('AIMW-COMM-85A340C8BC');
    });

    it('derives and encodes the destination only from authoritative tenant context', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <CommentsBackToSitesControl context={context('tenant with space')} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        expect(screen.getByRole('link', { name: /back to sites/i }))
            .toHaveAttribute('href', '/tenants/tenant%20with%20space/sites');
    });
});
