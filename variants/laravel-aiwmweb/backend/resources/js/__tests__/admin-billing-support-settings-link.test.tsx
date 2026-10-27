import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import {
    ADMIN_BILLING_SUPPORT_SETTINGS_LINK_OPERATION_ID,
    AdminBillingSupportSettingsLink,
} from '../admin-billing-support-settings-link';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (slug = 'alpha'): FrontendContext => ({
    user: { id: 10, name: 'Platform Admin', email: 'admin@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions: ['tenant.view'],
    connectors: [],
    capabilities: {},
    api: { 'admin-billing-support': `/api/tenants/${encodeURIComponent(slug)}/billing/admin/subscriptions` },
    actions: {},
});

describe('AIMW-BILL-7DACB1EFDF billing support back-to-settings link', () => {
    it('renders the canonical tenant-scoped settings destination', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <AdminBillingSupportSettingsLink context={context()} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        const link = screen.getByRole('link', { name: /Back to settings/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/settings');
        expect(link).toHaveAttribute('data-canonical-operation', ADMIN_BILLING_SUPPORT_SETTINGS_LINK_OPERATION_ID);
        expect(ADMIN_BILLING_SUPPORT_SETTINGS_LINK_OPERATION_ID).toBe('AIMW-BILL-7DACB1EFDF');
    });

    it('derives the path only from the authoritative active tenant slug', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <AdminBillingSupportSettingsLink context={context('alpha/../beta')} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        const link = screen.getByRole('link', { name: /Back to settings/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/settings');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/settings');
    });
});
