import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { GLOBAL_USERS_REFRESH_OPERATION_ID, GlobalUsersRefreshControl } from '../global-users-refresh-control';

type ActiveContext = FrontendContext & { active_site?: { id: number } | null };
const endpoint = '/api/v1/tenants/alpha/sites/7/users';
const context = (override: Partial<ActiveContext> = {}): ActiveContext => ({
    user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['tenant.view', 'sites.view'],
    connectors: [{ key: 'wordpress', state: 'connected', scopes: ['users.read'] }],
    capabilities: {}, api: { 'wp-users': endpoint }, actions: {},
    active_site: { id: 7 }, ...override,
});
afterEach(() => { vi.restoreAllMocks(); window.localStorage.clear(); });

describe('AIMW-SYNC-7C77CF7F7A GlobalUsersWorkspace RefreshAsync', () => {
    it('executes only the live selected-site authoritative users refresh', async () => {
        const onRefreshRequested = vi.fn().mockResolvedValue(undefined);
        const onRoleChange = vi.fn();
        render(<LocaleProvider><GlobalUsersRefreshControl context={context()} endpoint={endpoint}
            role="editor" onRoleChange={onRoleChange} busy={false} onRefreshRequested={onRefreshRequested} /></LocaleProvider>);
        const refresh = screen.getByRole('button', { name: 'Refresh global users' });
        expect(refresh).toHaveAttribute('data-canonical-operation', GLOBAL_USERS_REFRESH_OPERATION_ID);
        expect(screen.getByLabelText('User role')).toHaveValue('editor');
        fireEvent.click(refresh);
        await waitFor(() => expect(onRefreshRequested).toHaveBeenCalledWith(endpoint));
        expect(onRefreshRequested).toHaveBeenCalledTimes(1);
        fireEvent.change(screen.getByLabelText('User role'), { target: { value: 'author' } });
        expect(onRoleChange).toHaveBeenCalledWith('author');
    });
    it('rejects guests missing permission and foreign tenant/site context before read (403/404)', () => {
        const onRefreshRequested = vi.fn();
        for (const override of [
            { permissions: [] },
            { permissions: ['sites.view'] },
            { active_site: null },
            { active_site: { id: 99 } },
            { tenant: { slug: 'beta', name: 'Beta' } },
            { api: { 'wp-users': '/api/v1/tenants/alpha/sites/8/users' } },
        ] as Partial<ActiveContext>[]) {
            const view = render(<LocaleProvider><GlobalUsersRefreshControl context={context(override)}
                endpoint={endpoint} role="all" onRoleChange={vi.fn()} busy={false}
                onRefreshRequested={onRefreshRequested} /></LocaleProvider>);
            expect(view.queryByRole('button', { name: 'Refresh global users' })).toBeNull();
            view.unmount();
        }
        expect(onRefreshRequested).not.toHaveBeenCalled();
    });
    it('disables concurrent refresh and exposes the real WordPress error', async () => {
        const fail = vi.fn().mockRejectedValue(new Error('Remote WordPress denied read'));
        const props = { context: context(), endpoint, role: 'all' as const,
            onRoleChange: vi.fn(), onRefreshRequested: fail };
        const view = render(<LocaleProvider><GlobalUsersRefreshControl {...props} busy /></LocaleProvider>);
        expect(screen.getByRole('button')).toBeDisabled();
        fireEvent.click(screen.getByRole('button'));
        expect(fail).not.toHaveBeenCalled();
        view.rerender(<LocaleProvider><GlobalUsersRefreshControl {...props} busy={false} /></LocaleProvider>);
        fireEvent.click(screen.getByRole('button', { name: 'Refresh global users' }));
        await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Remote WordPress denied read'));
    });
    it('renders Arabic refresh with the same canonical operation', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        render(<LocaleProvider><GlobalUsersRefreshControl context={context()} endpoint={endpoint}
            role="all" onRoleChange={vi.fn()} busy={false} onRefreshRequested={vi.fn()} /></LocaleProvider>);
        expect(screen.getByRole('button', { name: 'تحديث المستخدمين العام' }))
            .toHaveAttribute('data-canonical-operation', GLOBAL_USERS_REFRESH_OPERATION_ID);
    });
});
