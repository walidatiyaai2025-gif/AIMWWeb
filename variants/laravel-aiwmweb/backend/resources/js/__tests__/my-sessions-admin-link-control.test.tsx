import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    MY_SESSIONS_ADMIN_LINK_OPERATION_ID,
    MySessionsAdminLinkControl,
} from '../my-sessions-admin-link-control';

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

describe('AIMW-CONT-2CDC790742 My Sessions admin sessions link', () => {
    it('renders the tenant-qualified real admin sessions destination for authorized users', () => {
        render(
            <LocaleProvider>
                <MySessionsAdminLinkControl
                    context={context(['tenant.view', 'sessions.manage', 'sessions.view', 'members.manage'])}
                />
            </LocaleProvider>,
        );

        const link = screen.getByRole('link', { name: 'Manage all sessions' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/admin/sessions');
        expect(link).toHaveAttribute('data-canonical-operation', MY_SESSIONS_ADMIN_LINK_OPERATION_ID);
        expect(MY_SESSIONS_ADMIN_LINK_OPERATION_ID).toBe('AIMW-CONT-2CDC790742');
    });

    it('fails closed and does not expose the control without the live admin destination permission', () => {
        render(
            <LocaleProvider>
                <MySessionsAdminLinkControl
                    context={context(['tenant.view', 'sessions.manage', 'sessions.view'])}
                />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('link', { name: 'Manage all sessions' })).not.toBeInTheDocument();
    });

    it('derives the destination only from the active tenant and encodes hostile slug input', () => {
        render(
            <LocaleProvider>
                <MySessionsAdminLinkControl context={context(['*'], 'alpha/../beta')} />
            </LocaleProvider>,
        );

        const link = screen.getByRole('link', { name: 'Manage all sessions' });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/admin/sessions');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/admin/sessions');
    });
});
