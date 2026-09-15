import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { type FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { SITE_DETAILS_BACK_OPERATION_ID, SiteDetailsBackControl } from '../site-details-back-control';

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

describe('canonical Site Details back control', () => {
    it('renders the exact canonical visible control to the active tenant Sites workspace', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <SiteDetailsBackControl context={context()} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        const link = screen.getByRole('link', { name: /back to sites/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites');
        expect(link.closest('nav')).toHaveAttribute('data-canonical-operation', SITE_DETAILS_BACK_OPERATION_ID);
        expect(SITE_DETAILS_BACK_OPERATION_ID).toBe('AIMW-AI-1C0C5D3B7B');
    });

    it('derives the destination only from the active context and safely encodes the tenant slug', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <SiteDetailsBackControl context={context('tenant with space')} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        expect(screen.getByRole('link', { name: /back to sites/i }))
            .toHaveAttribute('href', '/tenants/tenant%20with%20space/sites');
    });
});
