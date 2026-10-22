import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it } from 'vitest';
import { BILLING_EMAIL_SETTINGS_LINK_OPERATION, BillingProfileLink } from '../billing-profile-link';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (slug = 'alpha'): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions: ['tenant.view', 'tenant.manage', 'billing.view'],
    connectors: [],
    capabilities: {},
    api: {
        'account.billing': `/tenants/${encodeURIComponent(slug)}/route-api/billing-overview`,
        'account.profile': `/tenants/${encodeURIComponent(slug)}/route-api/account-profile`,
    },
    actions: {},
});

afterEach(() => {
    cleanup();
    window.localStorage.clear();
});

describe('AIMW-BILL-092F59830B billing Email settings navigation', () => {
    it('renders the source-faithful control with a tenant-derived destination', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <BillingProfileLink context={context()} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        const link = screen.getByRole('link', { name: /Email settings/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/account/email-settings');
        expect(link).toHaveAttribute('data-canonical-operation', BILLING_EMAIL_SETTINGS_LINK_OPERATION);
        expect(BILLING_EMAIL_SETTINGS_LINK_OPERATION).toBe('AIMW-BILL-092F59830B');
    });

    it('encodes the authoritative tenant slug and cannot be redirected to a caller-selected tenant', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <BillingProfileLink context={context('alpha/../beta')} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        const link = screen.getByRole('link', { name: /Email settings/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/account/email-settings');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/account/email-settings');
    });

    it('preserves the Arabic source label without changing the destination contract', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <BillingProfileLink context={context()} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        const link = screen.getByRole('link', { name: /إعدادات البريد/ });
        expect(link).toHaveAttribute('href', '/tenants/alpha/account/email-settings');
        expect(link).toHaveAttribute('data-canonical-operation', 'AIMW-BILL-092F59830B');
    });
});
