import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it } from 'vitest';
import {
    ACCOUNT_PROFILE_EMAIL_SETTINGS_LINK_OPERATION,
    AccountProfileEmailSettingsLinkControl,
} from '../account-profile-email-settings-link-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (slug = 'alpha'): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions: ['tenant.view'],
    connectors: [],
    capabilities: {},
    api: {
        'account.profile': `/tenants/${encodeURIComponent(slug)}/route-api/account-profile`,
    },
    actions: {},
});

afterEach(() => {
    cleanup();
    window.localStorage.clear();
});

describe('AIMW-EMAI-7A54150265 account profile Email settings navigation', () => {
    it('renders the source-faithful control with the exact canonical operation marker', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <AccountProfileEmailSettingsLinkControl context={context()} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        const link = screen.getByRole('link', { name: /Dashboard email settings/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/account/email-settings');
        expect(link).toHaveAttribute('data-canonical-operation', ACCOUNT_PROFILE_EMAIL_SETTINGS_LINK_OPERATION);
        expect(ACCOUNT_PROFILE_EMAIL_SETTINGS_LINK_OPERATION).toBe('AIMW-EMAI-7A54150265');
    });

    it('derives and encodes the destination only from the authoritative tenant context', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <AccountProfileEmailSettingsLinkControl context={context('alpha/../beta')} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        const link = screen.getByRole('link', { name: /Dashboard email settings/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/account/email-settings');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/account/email-settings');
    });

    it('preserves the Arabic source label without changing the tenant-scoped destination', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');

        render(
            <MemoryRouter>
                <LocaleProvider>
                    <AccountProfileEmailSettingsLinkControl context={context()} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        const link = screen.getByRole('link', { name: /إعدادات بريد الداشبورد/ });
        expect(link).toHaveAttribute('href', '/tenants/alpha/account/email-settings');
        expect(link).toHaveAttribute('data-canonical-operation', 'AIMW-EMAI-7A54150265');
    });
});
