import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_ADMIN_SESSIONS_LINK_OPERATION_ID,
    SettingsAdminSessionsLinkControl,
} from '../settings-admin-sessions-link-control';

const context = (permissions: string[], slug = 'alpha'): FrontendContext => ({
    user: { id: 10, name: 'Alpha Admin', email: 'admin@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

describe('AIMW-CONT-4A64732FC6 Settings admin sessions link', () => {
    it('renders the tenant-qualified real admin sessions destination for authorized users', () => {
        render(
            <LocaleProvider>
                <SettingsAdminSessionsLinkControl context={context(['tenant.view', 'members.manage'])} />
            </LocaleProvider>,
        );

        const link = screen.getByRole('link', { name: 'Session management' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/admin/sessions');
        expect(link).toHaveAttribute('data-canonical-operation', SETTINGS_ADMIN_SESSIONS_LINK_OPERATION_ID);
        expect(SETTINGS_ADMIN_SESSIONS_LINK_OPERATION_ID).toBe('AIMW-CONT-4A64732FC6');
    });

    it('fails closed without the source Users.Manage equivalent permission', () => {
        render(
            <LocaleProvider>
                <SettingsAdminSessionsLinkControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('link', { name: 'Session management' })).not.toBeInTheDocument();
    });

    it('derives the destination only from the active tenant and encodes hostile slug input', () => {
        render(
            <LocaleProvider>
                <SettingsAdminSessionsLinkControl context={context(['*'], 'alpha/../beta')} />
            </LocaleProvider>,
        );

        const link = screen.getByRole('link', { name: 'Session management' });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/admin/sessions');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/admin/sessions');
    });
});
