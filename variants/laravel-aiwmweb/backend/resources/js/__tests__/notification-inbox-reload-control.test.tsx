import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { NOTIFICATION_INBOX_RELOAD_OPERATION_ID, WorkspacePage } from '../pages';

function context(overrides: Partial<FrontendContext> = {}): FrontendContext {
    return {
        user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'notifications.view'],
        connectors: [],
        capabilities: {},
        api: { notifications: '/api/v1/tenants/alpha/notifications' },
        actions: {},
        ...overrides,
    };
}

const route: WorkspaceRoute = {
    key: 'notifications',
    path: '/notifications',
    group: 'operations',
    icon: '●',
    label: { en: 'Notification Inbox', ar: 'صندوق الإشعارات' },
    description: { en: 'Review operational and workflow notifications.', ar: 'مراجعة إشعارات التشغيل وسير العمل.' },
    apiKey: 'notifications',
    permission: 'notifications.view',
    kind: 'resource',
};

function renderWorkspace(value = context()) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <ToastProvider>
                    <WorkspacePage context={value} route={route} />
                </ToastProvider>
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('AIMW-EMAI-12A6FEB2FF notification Reload', () => {
    it('rereads the authoritative tenant notification endpoint using GET only', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ data: [{ id: 11, title: 'First state' }] }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ data: [{ id: 12, title: 'Refreshed state' }] }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        expect(await screen.findByText('First state')).toBeInTheDocument();
        const reload = screen.getByRole('button', { name: 'Refresh' });
        expect(reload).toHaveAttribute('data-canonical-operation', NOTIFICATION_INBOX_RELOAD_OPERATION_ID);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/tenants/alpha/notifications?page=1');

        fireEvent.click(reload);

        expect(await screen.findByText('Refreshed state')).toBeInTheDocument();
        expect(screen.queryByText('First state')).not.toBeInTheDocument();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(fetchMock.mock.calls[1][0]).toBe('/api/v1/tenants/alpha/notifications?page=1');
        expect(fetchMock.mock.calls.every(([, options]) => !options || options.method === undefined || options.method === 'GET')).toBe(true);
    });

    it('fails closed without notification workspace permission', async () => {
        vi.stubGlobal('fetch', vi.fn());
        renderWorkspace(context({ permissions: ['tenant.view'] }));
        expect(await screen.findByText('Permission required')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Refresh' })).not.toBeInTheDocument();
    });
});
