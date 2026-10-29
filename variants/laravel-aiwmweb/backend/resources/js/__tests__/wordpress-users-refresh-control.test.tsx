import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { apiRequest, type FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    authorizedWordPressUsersRefreshEndpoint,
    WORDPRESS_USERS_REFRESH_OPERATION_ID,
    WordPressUsersRefreshControl,
} from '../wordpress-users-refresh-control';

function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Alice', email: 'alice@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view'],
        connectors: [],
        capabilities: {},
        api: { 'wp-users': '/api/v1/tenants/alpha/sites/7/users' },
        actions: {},
        active_site: { id: 7 },
    } as FrontendContext;
}

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    window.localStorage.clear();
});

describe(`${WORDPRESS_USERS_REFRESH_OPERATION_ID} UsersManager RefreshClickedAsync`, () => {
    it('invokes the real active-site GET with current page and search, never a mutation', async () => {
        const responses = [
            [{ id: 1, username: 'before' }],
            [{ id: 2, username: 'after' }],
        ];
        const fetchMock = vi.fn().mockImplementation(async () => new Response(JSON.stringify({
            data: responses.shift(), total: 1, current_page: 2, last_page: 2,
        }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        const refreshed: unknown[] = [];
        const endpoint = context().api['wp-users'];
        const onReload = async () => {
            const result = await apiRequest<{ data: unknown[] }>(`${endpoint}?page=2&search=Alice`);
            refreshed.push(result.data[0]);
        };
        render(<LocaleProvider><WordPressUsersRefreshControl context={context()} endpoint={endpoint} onReload={onReload} /></LocaleProvider>);
        const button = screen.getByRole('button', { name: 'Refresh users' });
        expect(button).toHaveAttribute('data-canonical-operation', WORDPRESS_USERS_REFRESH_OPERATION_ID);
        fireEvent.click(button);
        await waitFor(() => expect(refreshed).toHaveLength(1));
        await waitFor(() => expect(button).not.toBeDisabled());
        fireEvent.click(button);
        await waitFor(() => expect(refreshed).toHaveLength(2));
        expect(refreshed).toEqual([{ id: 1, username: 'before' }, { id: 2, username: 'after' }]);
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls.every(([url, options]) =>
            url === '/api/v1/tenants/alpha/sites/7/users?page=2&search=Alice'
            && (!options || !options.method || options.method === 'GET'))).toBe(true);
    });

    it('fails closed for missing permissions or mismatched tenant/site endpoint', () => {
        const allowed = context();
        expect(authorizedWordPressUsersRefreshEndpoint(allowed, allowed.api['wp-users']))
            .toBe('/api/v1/tenants/alpha/sites/7/users');

        const noAccess = context();
        noAccess.permissions = ['tenant.view'];
        expect(authorizedWordPressUsersRefreshEndpoint(noAccess, noAccess.api['wp-users'])).toBeNull();

        const foreign = context();
        foreign.api['wp-users'] = '/api/v1/tenants/beta/sites/7/users';
        expect(authorizedWordPressUsersRefreshEndpoint(foreign, foreign.api['wp-users'])).toBeNull();
        expect(authorizedWordPressUsersRefreshEndpoint(context(), '/api/v1/tenants/alpha/sites/8/users')).toBeNull();

        const missing = context() as FrontendContext & { active_site?: { id: number } };
        missing.active_site = undefined;
        expect(authorizedWordPressUsersRefreshEndpoint(missing, missing.api['wp-users'])).toBeNull();

        const onReload = vi.fn();
        render(<LocaleProvider><WordPressUsersRefreshControl context={foreign} endpoint={foreign.api['wp-users']} onReload={onReload} /></LocaleProvider>);
        expect(screen.queryByRole('button', { name: 'Refresh users' })).not.toBeInTheDocument();
        expect(onReload).not.toHaveBeenCalled();
    });

    it('blocks duplicate refresh while busy and shows server errors without fake success', async () => {
        const onReload = vi.fn().mockRejectedValue(new Error('WordPress unavailable'));
        const endpoint = context().api['wp-users'];
        const view = render(<LocaleProvider><WordPressUsersRefreshControl context={context()} endpoint={endpoint} onReload={onReload} busy /></LocaleProvider>);
        expect(screen.getByRole('button', { name: 'Refreshing users…' })).toBeDisabled();
        view.rerender(<LocaleProvider><WordPressUsersRefreshControl context={context()} endpoint={endpoint} onReload={onReload} /></LocaleProvider>);
        fireEvent.click(screen.getByRole('button', { name: 'Refresh users' }));
        await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('WordPress unavailable'));
        expect(onReload).toHaveBeenCalledTimes(1);
    });

    it('renders the Arabic label for the same canonical operation', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        const c = context();
        render(<LocaleProvider><WordPressUsersRefreshControl context={c} endpoint={c.api['wp-users']} onReload={vi.fn().mockResolvedValue(undefined)} /></LocaleProvider>);
        const button = screen.getByRole('button', { name: 'تحديث المستخدمين' });
        expect(button).toHaveAttribute('data-canonical-operation', WORDPRESS_USERS_REFRESH_OPERATION_ID);
    });
});
