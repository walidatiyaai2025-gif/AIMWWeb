import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import {
    ACCOUNT_EMAIL_HISTORY_LINK_OPERATION_ID,
    AccountEmailSettingsHistoryLinkControl,
} from '../account-email-settings-history-link-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (permissions = ['tenant.manage', 'diagnostics.view'], slug = 'alpha'): FrontendContext => ({
    user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

function renderControl(value = context()) {
    return render(
        <MemoryRouter>
            <LocaleProvider>
                <AccountEmailSettingsHistoryLinkControl context={value} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

describe('AIMW-EMAI-0AA71A5EF6 account email delivery-history link', () => {
    it('opens the real tenant-qualified delivery history route', () => {
        renderControl(context(['tenant.manage', 'diagnostics.view'], 'alpha/../beta'));
        const link = screen.getByRole('link', { name: 'Delivery history' });

        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/email/history');
        expect(link).toHaveAttribute('data-canonical-operation', ACCOUNT_EMAIL_HISTORY_LINK_OPERATION_ID);
        expect(link).toHaveAttribute('data-source-path', '/account/email-settings');
        expect(link).toHaveAttribute('data-source-control-target', '/email/history');
    });

    it('fails closed unless all destination permissions are present', () => {
        const { rerender } = renderControl(context(['tenant.manage']));
        expect(screen.queryByRole('link', { name: 'Delivery history' })).not.toBeInTheDocument();

        rerender(
            <MemoryRouter>
                <LocaleProvider>
                    <AccountEmailSettingsHistoryLinkControl context={context(['diagnostics.view'])} />
                </LocaleProvider>
            </MemoryRouter>,
        );
        expect(screen.queryByRole('link', { name: 'Delivery history' })).not.toBeInTheDocument();
    });
});
