import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    ROLES_START_CREATE_OPERATION_ID,
    RolesStartCreateControl,
} from '../roles-start-create-control';

function context(permissions: string[]): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions,
        connectors: [],
        capabilities: {},
        api: { roles: '/tenants/alpha/admin/roles' },
        actions: {},
    };
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('AIMW-CONT-6CB3D0BE1B RolesPermissions StartCreate', () => {
    it('fails closed when roles.manage is absent', () => {
        render(
            <LocaleProvider>
                <RolesStartCreateControl
                    context={context(['tenant.view', 'roles.view'])}
                    onCreated={() => undefined}
                />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: /New role/i })).not.toBeInTheDocument();
    });

    it('opens a clean create state without a request, then uses the real role endpoint and reconciles', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ id: 9, name: 'support-agent', permissions: [] }), {
                status: 201,
                headers: { 'Content-Type': 'application/json' },
            }),
        );
        vi.stubGlobal('fetch', fetchMock);
        const onCreated = vi.fn().mockResolvedValue(undefined);

        render(
            <LocaleProvider>
                <RolesStartCreateControl
                    context={context(['tenant.view', 'roles.view', 'roles.manage'])}
                    onCreated={onCreated}
                />
            </LocaleProvider>,
        );

        const trigger = screen.getByRole('button', { name: /New role/i });
        expect(trigger).toHaveAttribute('data-canonical-operation', ROLES_START_CREATE_OPERATION_ID);
        expect(ROLES_START_CREATE_OPERATION_ID).toBe('AIMW-CONT-6CB3D0BE1B');

        fireEvent.click(trigger);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(await screen.findByRole('dialog', { name: /Create role/i })).toBeInTheDocument();
        const input = screen.getByRole('textbox', { name: /Role name/i });
        expect(input).toHaveValue('');

        fireEvent.change(input, { target: { value: ' support-agent ' } });
        fireEvent.click(screen.getByRole('button', { name: /^Create$/i }));

        await waitFor(() => expect(onCreated).toHaveBeenCalledTimes(1));
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledWith(
            '/tenants/alpha/admin/roles',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({ name: 'support-agent', permissions: [] }),
                credentials: 'same-origin',
            }),
        );
        await waitFor(() => expect(screen.queryByRole('dialog', { name: /Create role/i })).not.toBeInTheDocument());
    });

    it('stays disabled while authoritative role data is refreshing', () => {
        render(
            <LocaleProvider>
                <RolesStartCreateControl
                    context={context(['tenant.view', 'roles.view', 'roles.manage'])}
                    busy
                    onCreated={() => undefined}
                />
            </LocaleProvider>,
        );

        expect(screen.getByRole('button', { name: /New role/i })).toBeDisabled();
    });
});
