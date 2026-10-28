import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';
import {
    WORDPRESS_SITE_USERS_SEARCH_OPERATION_ID,
    isAuthorizedWordPressSiteUsersSearch,
    normalizeWordPressSiteUserRole,
} from '../wordpress-site-users-search-control';

const route: WorkspaceRoute = {
    key: 'wp-users', path: '/module/users', group: 'content', icon: 'x',
    label: { en: 'WordPress Users', ar: 'مستخدمو WordPress' },
    description: { en: 'WordPress Users', ar: 'مستخدمو WordPress' },
    apiKey: 'wp-users', permission: 'sites.view', controls: [], kind: 'resource',
};

const context: FrontendContext = {
    user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['tenant.view', 'sites.view'],
    connectors: [], capabilities: {},
    api: { 'wp-users': '/api/v1/tenants/alpha/sites/7/users' },
    actions: {},
};

function renderUsers() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider><ToastProvider><WorkspacePage context={context} route={route} /></ToastProvider></LocaleProvider>
        </QueryClientProvider>,
    );
}

function response(id: number, name: string) {
    return new Response(JSON.stringify({
        data: [{ id, username: name, name, email: name + '@example.test' }],
        current_page: 1, last_page: 1, total: 1,
    }), { status: 200, headers: { 'content-type': 'application/json' } });
}

afterEach(() => {
    vi.unstubAllGlobals();
    window.history.replaceState({}, '', '/');
});

describe('AIMW-SYNC-4FDCFDA33F UsersManager SearchClickedAsync', () => {
    it('loads the exact tenant/site from the trusted context, applies role and re-reads even an unchanged search', async () => {
        window.history.replaceState({}, '', '/tenants/alpha/module/users?site=7');
        expect(WORDPRESS_SITE_USERS_SEARCH_OPERATION_ID).toBe('AIMW-SYNC-4FDCFDA33F');

        const fetchMock = vi.fn()
            .mockResolvedValueOnce(response(1, 'Initial'))
            .mockResolvedValueOnce(response(2, 'Needle'))
            .mockResolvedValueOnce(response(3, 'Refreshed'));
        vi.stubGlobal('fetch', fetchMock);

        renderUsers();
        expect(await screen.findByRole('cell', { name: '1' })).toBeInTheDocument();

        const searchButton = screen.getByRole('button', { name: 'Search' });
        expect(searchButton).toHaveAttribute('data-canonical-operation', WORDPRESS_SITE_USERS_SEARCH_OPERATION_ID);
        fireEvent.change(screen.getByRole('textbox', { name: 'Search' }), { target: { value: ' needle ' } });
        fireEvent.change(screen.getByRole('combobox', { name: 'WordPress user role' }), { target: { value: 'editor' } });
        fireEvent.click(searchButton);

        expect(await screen.findByRole('cell', { name: '2' })).toBeInTheDocument();
        expect(fetchMock.mock.calls[1][0]).toBe('/api/v1/tenants/alpha/sites/7/users?page=1&search=needle&role=editor');

        fireEvent.click(screen.getByRole('button', { name: 'Search' }));
        expect(await screen.findByRole('cell', { name: '3' })).toBeInTheDocument();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));
        expect(fetchMock.mock.calls[2][0]).toBe('/api/v1/tenants/alpha/sites/7/users?page=1&search=needle&role=editor');
        expect(fetchMock.mock.calls.every(([, options]) => !options || !options.method || options.method === 'GET')).toBe(true);
    });

    it('surfaces a WordPress authorization failure without false success', async () => {
        window.history.replaceState({}, '', '/tenants/alpha/module/users?site=7');
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(response(1, 'Initial'))
            .mockResolvedValueOnce(new Response(JSON.stringify({ message: 'WordPress: rest_forbidden_context' }), {
                status: 403, headers: { 'content-type': 'application/json' },
            }));
        vi.stubGlobal('fetch', fetchMock);
        renderUsers();
        expect(await screen.findByRole('cell', { name: '1' })).toBeInTheDocument();
        fireEvent.change(screen.getByRole('textbox', { name: 'Search' }), { target: { value: 'denied' } });
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));
        expect(await screen.findByText('Data could not be loaded')).toBeInTheDocument();
        expect(screen.getByText('WordPress: rest_forbidden_context')).toBeInTheDocument();
        expect(screen.queryByText(/success/i)).toBeNull();
    });

    it('does not bind the site manager operation to a forged cross-tenant or foreign-site URL', () => {
        expect(isAuthorizedWordPressSiteUsersSearch(context, '?site=7')).toBe(true);
        expect(isAuthorizedWordPressSiteUsersSearch(context, '?site=8')).toBe(false);
        expect(isAuthorizedWordPressSiteUsersSearch(context, '?site=0')).toBe(false);
        expect(isAuthorizedWordPressSiteUsersSearch(context, '?site=7%26tenant=foreign')).toBe(false);
        expect(isAuthorizedWordPressSiteUsersSearch(
            { ...context, tenant: { slug: 'foreign', name: 'Foreign' } }, '?site=7',
        )).toBe(false);
        expect(isAuthorizedWordPressSiteUsersSearch(
            { ...context, permissions: ['tenant.view'] }, '?site=7',
        )).toBe(false);
        expect(normalizeWordPressSiteUserRole('administrator')).toBe('administrator');
        expect(normalizeWordPressSiteUserRole('arbitrary-privileged-role')).toBe('all');
    });
});
