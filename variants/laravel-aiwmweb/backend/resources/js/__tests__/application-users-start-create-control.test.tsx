import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    APPLICATION_USERS_START_CREATE_OPERATION_ID,
    ApplicationUsersStartCreateControl,
} from '../application-users-start-create-control';

const context = (permissions: string[]): FrontendContext => ({
    user: { id: 1, name: 'Owner', email: 'owner@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {
        'application-users': '/tenants/alpha/admin/members',
        roles: '/tenants/alpha/admin/roles',
    },
    actions: {},
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('AIMW-CONT-E38ADF8481 Application Users StartCreateClicked', () => {
    it('fails closed when members.manage is absent', () => {
        render(
            <LocaleProvider>
                <ApplicationUsersStartCreateControl context={context(['tenant.view', 'users.view'])} onCreated={() => undefined} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: /New user/i })).not.toBeInTheDocument();
    });

    it('opens a clean create form, loads authoritative roles, posts the real member request and reconciles', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ data: [{ id: 7, name: 'Editor' }] }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ kind: 'member', membership_id: 22 }), {
                status: 201,
                headers: { 'Content-Type': 'application/json' },
            }));
        vi.stubGlobal('fetch', fetchMock);
        const onCreated = vi.fn().mockResolvedValue(undefined);

        render(
            <LocaleProvider>
                <ApplicationUsersStartCreateControl context={context(['tenant.view', 'members.manage'])} onCreated={onCreated} />
            </LocaleProvider>,
        );

        const trigger = screen.getByRole('button', { name: /New user/i });
        expect(trigger).toHaveAttribute('data-canonical-operation', APPLICATION_USERS_START_CREATE_OPERATION_ID);
        expect(APPLICATION_USERS_START_CREATE_OPERATION_ID).toBe('AIMW-CONT-E38ADF8481');

        fireEvent.click(trigger);
        expect(await screen.findByRole('dialog', { name: /Create user/i })).toBeInTheDocument();
        expect(await screen.findByRole('option', { name: 'Editor' })).toBeInTheDocument();

        fireEvent.change(screen.getByRole('textbox', { name: /Email/i }), { target: { value: 'member@example.test' } });
        fireEvent.change(screen.getByRole('combobox', { name: /Role/i }), { target: { value: '7' } });
        fireEvent.click(screen.getByRole('button', { name: /^Create$/i }));

        await waitFor(() => expect(onCreated).toHaveBeenCalledTimes(1));
        expect(fetchMock).toHaveBeenNthCalledWith(
            1,
            '/tenants/alpha/admin/roles',
            expect.objectContaining({ credentials: 'same-origin' }),
        );
        expect(fetchMock).toHaveBeenNthCalledWith(
            2,
            '/tenants/alpha/admin/members',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({ email: 'member@example.test', role_id: 7 }),
                credentials: 'same-origin',
            }),
        );
        await waitFor(() => expect(screen.queryByRole('dialog', { name: /Create user/i })).not.toBeInTheDocument());
    });
});
