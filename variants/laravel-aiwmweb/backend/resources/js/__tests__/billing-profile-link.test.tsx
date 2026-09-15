import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { BILLING_PROFILE_LINK_OPERATION, BillingProfileLink } from '../billing-profile-link';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (slug = 'alpha'): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions: ['tenant.view', 'billing.view'],
    connectors: [],
    capabilities: {},
    api: {
        'account.billing': `/tenants/${encodeURIComponent(slug)}/route-api/billing-overview`,
        'account.profile': `/tenants/${encodeURIComponent(slug)}/route-api/account-profile`,
    },
    actions: {},
});

describe('AIMW-BILL-67B6CF3962 billing profile link', () => {
    it('renders the real canonical My Account control for the active tenant', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <BillingProfileLink context={context()} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        const link = screen.getByRole('link', { name: /My Account/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/account/profile');
        expect(link).toHaveAttribute('data-canonical-operation', BILLING_PROFILE_LINK_OPERATION);
        expect(BILLING_PROFILE_LINK_OPERATION).toBe('AIMW-BILL-67B6CF3962');
    });

    it('encodes the authoritative tenant slug instead of accepting a cross-tenant path fragment', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <BillingProfileLink context={context('alpha/../beta')} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        const link = screen.getByRole('link', { name: /My Account/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/account/profile');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/account/profile');
    });
});
