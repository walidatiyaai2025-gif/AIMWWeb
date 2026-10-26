import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    ROLES_PERMISSIONS_START_CREATE_OPERATION_ID,
    RolesPermissionsStartCreateControl,
} from '../roles-permissions-start-create-control';

const context = (permissions: string[]): FrontendContext => ({
    user: { id: 1, name: 'Owner', email: 'owner@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: { roles: '/tenants/alpha/admin/roles' },
    actions: {},
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('AIMW-CONT-6CB3D0BE1B RolesPermissions StartCreate', () => {
    it('fails closed when roles.manage is absent', () => {
        render(
            <LocaleProvider>
                <RolesPermissionsStartCreateControl context={context(['tenant.view', 'roles.view'])} onCreated={() => undefined} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: /New role/i })).not.toBeInTheDocument();
    });

    it('starts from a clean editor and persists through the authoritative tenant role endpoint', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            id: 9,
            name: 'editor',
            permissions: ['content.view', 'sites.view'],
        }), {
            status: 201,
            headers: { 'Content-Type': 'application/json' },
        }));
        vi.stubGlobal('fetch', fetchMock);
        const onCreated = vi.fn().mockResolvedValue(undefined);

        render(
            <LocaleProvider>
                <RolesPermissionsStartCreateControl context={context(['tenant.view', 'roles.manage'])} onCreated={onCreated} />
            </LocaleProvider>,
        );

        const trigger = screen.getByRole('button', { name: /New role/i });
        expect(trigger).toHaveAttribute('data-canonical-operation', ROLES_PERMISSIONS_START_CREATE_OPERATION_ID);
        expect(ROLES_PERMISSIONS_START_CREATE_OPERATION_ID).toBe('AIMW-CONT-6CB3D0BE1B');

        fireEvent.click(trigger);
        expect(await screen.findByRole('dialog', { name: /Create role/i })).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText(/Role name/i), { target: { value: 'stale-value' } });
        fireEvent.change(screen.getByLabelText(/Permission keys/i), { target: { value: 'old.permission' } });
        fireEvent.click(screen.getByRole('button', { name: /^Cancel$/i }));

        fireEvent.click(trigger);
        expect(screen.getByLabelText(/Role name/i)).toHaveValue('');
        expect(screen.getByLabelText(/Permission keys/i)).toHaveValue('');

        fireEvent.change(screen.getByLabelText(/Role name/i), { target: { value: ' editor ' } });
        fireEvent.change(screen.getByLabelText(/Permission keys/i), { target: { value: 'content.view, sites.view, content.view' } });
        fireEvent.click(screen.getByRole('button', { name: /^Create$/i }));

        await waitFor(() => expect(onCreated).toHaveBeenCalledTimes(1));
        expect(fetchMock).toHaveBeenCalledWith(
            '/tenants/alpha/admin/roles',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({
                    name: 'editor',
                    permissions: ['content.view', 'sites.view'],
                }),
                credentials: 'same-origin',
            }),
        );
        await waitFor(() => expect(screen.queryByRole('dialog', { name: /Create role/i })).not.toBeInTheDocument());
    });
});
