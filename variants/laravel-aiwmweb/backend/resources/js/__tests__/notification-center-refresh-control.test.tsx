import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    NOTIFICATION_CENTER_REFRESH_OPERATION_ID,
    NotificationCenterToggleControl,
} from '../notification-center-toggle-control';

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

function renderControl(value = context()) {
    return render(
        <MemoryRouter>
            <LocaleProvider>
                <NotificationCenterToggleControl context={value} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

beforeEach(() => {
    const target = document.createElement('div');
    target.className = 'topbar-actions';
    document.body.appendChild(target);
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    document.querySelector('.topbar-actions')?.remove();
});

describe('AIMW-EMAI-62B0B8EE4C NotificationCenter RefreshAsync', () => {
    it('performs a fresh authoritative tenant read and disables refresh while loading', async () => {
        let resolveRefresh: (response: Response) => void = () => undefined;
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{
                    id: 1,
                    title: 'Before refresh',
                    message: 'Old authoritative row',
                    is_read: false,
                    created_at: '2026-10-14T04:00:00Z',
                }],
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockImplementationOnce(() => new Promise<Response>((resolve) => {
                resolveRefresh = resolve;
            }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        fireEvent.click(await screen.findByRole('button', { name: 'Notification center' }));
        expect(await screen.findByText('Before refresh')).toBeInTheDocument();

        const refresh = screen.getByRole('button', { name: 'Refresh' });
        expect(refresh).toHaveAttribute('data-canonical-operation', NOTIFICATION_CENTER_REFRESH_OPERATION_ID);
        expect(NOTIFICATION_CENTER_REFRESH_OPERATION_ID).toBe('AIMW-EMAI-62B0B8EE4C');
        expect(refresh).toBeEnabled();

        fireEvent.click(refresh);

        expect(await screen.findByRole('status')).toHaveTextContent('Loading notifications');
        expect(refresh).toBeDisabled();
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls[1][0]).toBe('/api/v1/tenants/alpha/notifications?page=1');
        expect(fetchMock.mock.calls[1][1]).toMatchObject({ credentials: 'same-origin' });
        expect(fetchMock.mock.calls[1][1]?.method).toBeUndefined();

        fireEvent.click(refresh);
        expect(fetchMock).toHaveBeenCalledTimes(2);

        resolveRefresh(new Response(JSON.stringify({
            data: [{
                id: 2,
                title: 'After refresh',
                message: 'Fresh authoritative row',
                is_read: true,
                created_at: '2026-10-14T04:05:00Z',
            }],
        }), { status: 200, headers: { 'content-type': 'application/json' } }));

        expect(await screen.findByText('After refresh')).toBeInTheDocument();
        await waitFor(() => expect(screen.queryByText('Before refresh')).not.toBeInTheDocument());
        expect(refresh).toBeEnabled();
        expect(screen.getByText('0 unread')).toBeInTheDocument();
    });

    it('renders an authoritative error and allows a later refresh to recover', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ data: [] }), {
                status: 200,
                headers: { 'content-type': 'application/json' },
            }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ message: 'Provider unavailable' }), {
                status: 503,
                headers: { 'content-type': 'application/json' },
            }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{
                    id: 3,
                    title: 'Recovered notification',
                    is_read: false,
                }],
            }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        fireEvent.click(await screen.findByRole('button', { name: 'Notification center' }));
        expect(await screen.findByText("You're all caught up")).toBeInTheDocument();

        const refresh = screen.getByRole('button', { name: 'Refresh' });
        fireEvent.click(refresh);

        expect(await screen.findByRole('alert')).toHaveTextContent('Provider unavailable');

        fireEvent.click(refresh);

        expect(await screen.findByText('Recovered notification')).toBeInTheDocument();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    it('fails closed when the notification capability is unavailable', async () => {
        vi.stubGlobal('fetch', vi.fn());

        const first = renderControl(context({ permissions: ['tenant.view'] }));
        await waitFor(() => expect(screen.queryByRole('button', { name: 'Refresh' })).not.toBeInTheDocument());
        first.unmount();

        renderControl(context({ api: {} }));
        await waitFor(() => expect(screen.queryByRole('button', { name: 'Refresh' })).not.toBeInTheDocument());
    });
});
