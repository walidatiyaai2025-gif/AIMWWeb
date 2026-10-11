import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_BILLING_SUPPORT_LINK_OPERATION_ID,
    SettingsBillingSupportLinkControl,
} from '../settings-billing-support-link-control';

const context = (permissions: string[], slug = 'alpha'): FrontendContext => ({
    user: { id: 10, name: 'Alpha Admin', email: 'admin@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: { 'admin-billing-support': `/api/tenants/${encodeURIComponent(slug)}/billing/admin/subscriptions` },
    actions: {},
});

describe('AIMW-BILL-81D0940A0A Settings Billing Support link', () => {
    it('renders the canonical tenant-scoped Billing Support destination for settings managers', () => {
        render(
            <LocaleProvider>
                <SettingsBillingSupportLinkControl context={context(['tenant.view', 'settings.manage'])} />
            </LocaleProvider>,
        );

        const link = screen.getByRole('link', { name: /Billing support/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/admin/billing-support');
        expect(link).toHaveAttribute('data-canonical-operation', SETTINGS_BILLING_SUPPORT_LINK_OPERATION_ID);
        expect(SETTINGS_BILLING_SUPPORT_LINK_OPERATION_ID).toBe('AIMW-BILL-81D0940A0A');
    });

    it('does not expose the settings-managed control without settings.manage', () => {
        render(
            <LocaleProvider>
                <SettingsBillingSupportLinkControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('link', { name: /Billing support/i })).not.toBeInTheDocument();
    });

    it('accepts wildcard settings authority and derives the target only from the active tenant slug', () => {
        render(
            <LocaleProvider>
                <SettingsBillingSupportLinkControl context={context(['*'], 'alpha/../beta')} />
            </LocaleProvider>,
        );

        const link = screen.getByRole('link', { name: /Billing support/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/admin/billing-support');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/admin/billing-support');
    });
});
