import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    APPLICATION_USERS_ROLES_PERMISSIONS_OPERATION_ID,
    ApplicationUsersRolesPermissionsLinkControl,
} from '../application-users-roles-permissions-link-control';

const context = (permissions: string[], slug = 'alpha'): FrontendContext => ({
    user: { id: 10, name: 'Alpha Admin', email: 'admin@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: { roles: `/tenants/${encodeURIComponent(slug)}/admin/roles` },
    actions: {},
});

describe('AIMW-CONT-1A08B49164 Application Users roles & permissions link', () => {
    it('renders the exact tenant-scoped source destination for roles viewers', () => {
        render(
            <LocaleProvider>
                <ApplicationUsersRolesPermissionsLinkControl context={context(['tenant.view', 'roles.view'])} />
            </LocaleProvider>,
        );

        const link = screen.getByRole('link', { name: /Roles & permissions/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/admin/roles-permissions');
        expect(link).toHaveAttribute('data-canonical-operation', APPLICATION_USERS_ROLES_PERMISSIONS_OPERATION_ID);
        expect(APPLICATION_USERS_ROLES_PERMISSIONS_OPERATION_ID).toBe('AIMW-CONT-1A08B49164');
    });

    it('fails closed when roles.view is absent', () => {
        render(
            <LocaleProvider>
                <ApplicationUsersRolesPermissionsLinkControl context={context(['tenant.view', 'users.view'])} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('link', { name: /Roles & permissions/i })).not.toBeInTheDocument();
    });

    it('accepts wildcard authority and encodes the active tenant slug', () => {
        render(
            <LocaleProvider>
                <ApplicationUsersRolesPermissionsLinkControl context={context(['*'], 'alpha/../beta')} />
            </LocaleProvider>,
        );

        const link = screen.getByRole('link', { name: /Roles & permissions/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/admin/roles-permissions');
    });
});
