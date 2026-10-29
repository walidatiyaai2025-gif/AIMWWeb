import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import {
    GLOBAL_USERS_FULL_MANAGEMENT_OPERATION_ID,
    GlobalUsersFullManagementControl,
    authoritativeGlobalUsersManagementHref,
} from '../global-users-full-management-control';

type ContextWithActiveSite = FrontendContext & {
    active_site?: { id: number; name: string; status?: string | null } | null;
};

const route: WorkspaceRoute = {
    key: 'wp-users',
    path: '/module/users',
    group: 'content',
    icon: '◎',
    label: { en: 'WordPress Users', ar: 'مستخدمو WordPress' },
    description: { en: 'Manage WordPress users.', ar: 'إدارة مستخدمي WordPress.' },
    apiKey: 'wp-users',
    permission: 'sites.view',
    connectorScope: 'users.read',
    controls: ['wordpress-users.save'],
    kind: 'resource',
};

function context(overrides: Partial<ContextWithActiveSite> = {}): ContextWithActiveSite {
    const base: ContextWithActiveSite = {
        user: { id: 1, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view'],
        connectors: [{ key: 'wordpress-alpha', state: 'connected', scopes: ['users.read'] }],
        capabilities: {},
        api: {
            'sites.detail.42': '/api/tenants/alpha/sites/42',
            'wp-users': '/api/v1/tenants/alpha/sites/42/users',
        },
        actions: {},
        active_site: { id: 42, name: 'Alpha WordPress', status: 'active' },
    };

    return { ...base, ...overrides };
}

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    window.localStorage.clear();
});

describe(`${GLOBAL_USERS_FULL_MANAGEMENT_OPERATION_ID} Global Users full-management navigation`, () => {
    it('renders the source-equivalent control from authoritative active-site context without an HTTP mutation', () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);

        render(
            <LocaleProvider>
                <GlobalUsersFullManagementControl context={context()} route={route} />
            </LocaleProvider>,
        );

        const link = screen.getByRole('link', { name: 'Full user management' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites/42/users');
        expect(link).toHaveAttribute('data-canonical-operation', GLOBAL_USERS_FULL_MANAGEMENT_OPERATION_ID);
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('preserves Arabic copy on the same tenant-bound destination', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');

        render(
            <LocaleProvider>
                <GlobalUsersFullManagementControl context={context()} route={route} />
            </LocaleProvider>,
        );

        expect(screen.getByRole('link', { name: 'الإدارة الكاملة' }))
            .toHaveAttribute('href', '/tenants/alpha/sites/42/users');
    });

    it('fails closed when the active site or authoritative API bindings are missing or inconsistent', () => {
        const cases: ContextWithActiveSite[] = [
            context({ active_site: null }),
            context({ active_site: { id: 0, name: 'Invalid' } }),
            context({ active_site: { id: 1.5, name: 'Invalid' } }),
            context({ permissions: ['tenant.view'] }),
            context({ permissions: ['sites.view'] }),
            context({ api: { 'sites.detail.42': '/api/tenants/beta/sites/42', 'wp-users': '/api/v1/tenants/alpha/sites/42/users' } }),
            context({ api: { 'sites.detail.42': '/api/tenants/alpha/sites/42', 'wp-users': '/api/v1/tenants/alpha/sites/99/users' } }),
        ];

        for (const candidate of cases) {
            const { unmount } = render(
                <LocaleProvider>
                    <GlobalUsersFullManagementControl context={candidate} route={route} />
                </LocaleProvider>,
            );
            expect(screen.queryByRole('link', { name: /Full user management|الإدارة الكاملة/ })).not.toBeInTheDocument();
            unmount();
        }
    });

    it('fails closed when the WordPress users capability is unavailable', () => {
        const disconnected = context({
            connectors: [{ key: 'wordpress-alpha', state: 'disconnected', scopes: ['users.read'] }],
        });

        render(
            <LocaleProvider>
                <GlobalUsersFullManagementControl context={disconnected} route={route} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('link', { name: 'Full user management' })).not.toBeInTheDocument();
    });

    it('derives the destination only from the active tenant slug and positive authoritative site id', () => {
        expect(authoritativeGlobalUsersManagementHref(context()))
            .toBe('/tenants/alpha/sites/42/users');

        const encoded = context({
            tenant: { slug: 'alpha team', name: 'Alpha Team' },
            tenants: [{ slug: 'alpha team', name: 'Alpha Team' }],
            api: {
                'sites.detail.42': '/api/tenants/alpha team/sites/42',
                'wp-users': '/api/v1/tenants/alpha team/sites/42/users',
            },
        });
        expect(authoritativeGlobalUsersManagementHref(encoded))
            .toBe('/tenants/alpha%20team/sites/42/users');
    });
});
