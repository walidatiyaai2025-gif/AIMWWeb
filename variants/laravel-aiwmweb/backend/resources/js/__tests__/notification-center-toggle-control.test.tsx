import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    NOTIFICATION_CENTER_TOGGLE_OPERATION_ID,
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

describe('AIMW-EMAI-8E3B0AACD6 NotificationCenter ToggleAsync', () => {
    it('toggles the popover and reads authoritative tenant notifications only when opening', async () => {
        let resolveFetch: (response: Response) => void = () => undefined;
        const fetchMock = vi.fn().mockImplementation(() => new Promise<Response>((resolve) => {
            resolveFetch = resolve;
        }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        const trigger = await screen.findByRole('button', { name: 'Notification center' });
        expect(trigger).toHaveAttribute('data-canonical-operation', NOTIFICATION_CENTER_TOGGLE_OPERATION_ID);
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        expect(fetchMock).not.toHaveBeenCalled();

        fireEvent.click(trigger);

        expect(trigger).toHaveAttribute('aria-expanded', 'true');
        expect(await screen.findByRole('status')).toHaveTextContent('Loading notifications');

        resolveFetch(new Response(JSON.stringify({
            data: [
                {
                    id: 11,
                    title: 'Sync completed',
                    message: 'The authoritative sync finished.',
                    severity: 'success',
                    is_read: false,
                    created_at: '2026-10-14T04:00:00Z',
                },
            ],
        }), { status: 200, headers: { 'content-type': 'application/json' } }));

        expect(await screen.findByText('Sync completed')).toBeInTheDocument();
        expect(screen.getByText('1 unread')).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/api/v1/tenants/alpha/notifications?page=1');
        expect(fetchMock.mock.calls[0][1]).toMatchObject({ credentials: 'same-origin' });
        expect(fetchMock.mock.calls[0][1]?.method).toBeUndefined();

        fireEvent.click(trigger);

        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        expect(screen.queryByLabelText('Notification center', { selector: 'section' })).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('renders deterministic empty and error states from the authoritative read', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ data: [] }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ message: 'Provider unavailable' }), { status: 503, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        const trigger = await screen.findByRole('button', { name: 'Notification center' });
        fireEvent.click(trigger);
        expect(await screen.findByText("You're all caught up")).toBeInTheDocument();

        fireEvent.click(trigger);
        fireEvent.click(trigger);

        expect(await screen.findByRole('alert')).toHaveTextContent('Notifications could not be loaded');
        expect(screen.getByRole('alert')).toHaveTextContent('Provider unavailable');
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('fails closed when notification permission or endpoint is unavailable', async () => {
        vi.stubGlobal('fetch', vi.fn());
        const first = renderControl(context({ permissions: ['tenant.view'] }));
        await waitFor(() => expect(screen.queryByRole('button', { name: 'Notification center' })).not.toBeInTheDocument());
        first.unmount();

        renderControl(context({ api: {} }));
        await waitFor(() => expect(screen.queryByRole('button', { name: 'Notification center' })).not.toBeInTheDocument());
    });
});
