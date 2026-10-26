import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    workspaceRoutes,
    type FrontendContext,
} from '../core';
import { LocaleProvider } from '../i18n';
import {
    ROLES_START_CREATE_OPERATION_ID,
    RolesStartCreateControl,
} from '../roles-start-create-control';

const rolesRoute = workspaceRoutes.find((route) => route.key === 'roles')!;

function context(withCreateAction: boolean): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'roles.view', 'roles.manage'],
        connectors: [],
        capabilities: {},
        api: { roles: '/tenants/alpha/admin/roles' },
        actions: withCreateAction
            ? {
                'roles.create': {
                    endpoint: '/tenants/alpha/admin/roles',
                    method: 'POST',
                    fields: [
                        { key: 'name', type: 'text', label: { en: 'Role name', ar: 'اسم الدور' }, required: true },
                    ],
                },
            }
            : {},
    };
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe('AIMW-CONT-6CB3D0BE1B RolesPermissions StartCreate', () => {
    it('opens the existing authoritative roles.create contract without issuing a request', () => {
        const onAvailable = vi.fn();
        const fetchSpy = vi.spyOn(globalThis, 'fetch');

        render(
            <LocaleProvider>
                <RolesStartCreateControl
                    context={context(true)}
                    route={rolesRoute}
                    onAvailable={onAvailable}
                />
            </LocaleProvider>,
        );

        const trigger = screen.getByRole('button', { name: /New role/i });
        expect(trigger).toHaveAttribute('data-canonical-operation', ROLES_START_CREATE_OPERATION_ID);
        expect(ROLES_START_CREATE_OPERATION_ID).toBe('AIMW-CONT-6CB3D0BE1B');

        fireEvent.click(trigger);

        expect(onAvailable).toHaveBeenCalledTimes(1);
        expect(onAvailable).toHaveBeenCalledWith(context(true).actions['roles.create']);
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('fails closed when the server does not advertise roles.create', () => {
        render(
            <LocaleProvider>
                <RolesStartCreateControl
                    context={context(false)}
                    route={rolesRoute}
                    onAvailable={() => undefined}
                />
            </LocaleProvider>,
        );

        const trigger = screen.getByRole('button', { name: /New role/i });
        expect(trigger).toBeDisabled();
        expect(screen.getByText(/backend integration/i)).toBeInTheDocument();
    });

    it('stays disabled while another role operation is in progress', () => {
        render(
            <LocaleProvider>
                <RolesStartCreateControl
                    context={context(true)}
                    route={rolesRoute}
                    busy
                    onAvailable={() => undefined}
                />
            </LocaleProvider>,
        );

        expect(screen.getByRole('button', { name: /New role/i })).toBeDisabled();
        expect(screen.getByText(/role operation is in progress/i)).toBeInTheDocument();
    });
});
