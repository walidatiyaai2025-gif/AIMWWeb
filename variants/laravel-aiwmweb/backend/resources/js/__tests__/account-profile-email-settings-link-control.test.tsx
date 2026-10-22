import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import {
    ACCOUNT_PROFILE_EMAIL_SETTINGS_OPERATION_ID,
    AccountProfileEmailSettingsLinkControl,
} from '../account-profile-email-settings-link-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (permissions = ['tenant.view'], slug = 'alpha'): FrontendContext => ({
    user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: { 'account.profile': `/tenants/${encodeURIComponent(slug)}/route-api/account-profile` },
    actions: {},
});

function renderControl(value = context()) {
    return render(
        <MemoryRouter>
            <LocaleProvider>
                <AccountProfileEmailSettingsLinkControl context={value} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

describe('AIMW-EMAI-7A54150265 account profile email-settings link', () => {
    it('opens the real tenant-qualified account email settings route', () => {
        renderControl(context(['tenant.view'], 'alpha/../beta'));
        const link = screen.getByRole('link', { name: 'Dashboard email settings' });

        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/account/email-settings');
        expect(link).toHaveAttribute('data-canonical-operation', ACCOUNT_PROFILE_EMAIL_SETTINGS_OPERATION_ID);
        expect(link).toHaveAttribute('data-source-path', '/account/profile');
        expect(link).toHaveAttribute('data-source-control-target', '/account/email-settings');
    });

    it('fails closed when tenant workspace access is unavailable', () => {
        renderControl(context([]));
        expect(screen.queryByRole('link', { name: 'Dashboard email settings' })).not.toBeInTheDocument();
    });
});
