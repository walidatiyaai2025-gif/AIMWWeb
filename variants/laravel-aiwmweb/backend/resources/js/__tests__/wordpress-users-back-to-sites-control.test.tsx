import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { WORDPRESS_USERS_BACK_TO_SITES_OPERATION_ID, WordPressUsersBackToSitesControl } from '../wordpress-users-back-to-sites-control';
import { type FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

function context(slug = 'alpha'): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug, name: 'Active tenant' },
        tenants: [{ slug, name: 'Active tenant' }],
        permissions: ['tenant.view', 'sites.view'],
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
    };
}

describe('canonical WordPress users back-to-sites control', () => {
    it('renders the exact canonical navigation control for the active tenant', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <WordPressUsersBackToSitesControl context={context()} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        const link = screen.getByRole('link', { name: /back to sites/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites');
        expect(link.closest('nav')).toHaveAttribute('data-canonical-operation', WORDPRESS_USERS_BACK_TO_SITES_OPERATION_ID);
        expect(WORDPRESS_USERS_BACK_TO_SITES_OPERATION_ID).toBe('AIMW-CONT-B1024628D9');
    });

    it('derives and encodes the destination only from authoritative tenant context', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <WordPressUsersBackToSitesControl context={context('tenant with space')} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        expect(screen.getByRole('link', { name: /back to sites/i }))
            .toHaveAttribute('href', '/tenants/tenant%20with%20space/sites');
    });
});
