import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    NOTIFICATION_CENTER_CLOSE_OPERATION_ID,
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

describe('AIMW-EMAI-77C637E3E3 NotificationCenter Close', () => {
    it('closes the open popover without an extra read or mutation', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            data: [{
                id: 1,
                title: 'Authoritative notification',
                is_read: false,
            }],
        }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        const trigger = await screen.findByRole('button', { name: 'Notification center' });
        fireEvent.click(trigger);

        expect(await screen.findByText('Authoritative notification')).toBeInTheDocument();

        const close = screen.getByRole('button', { name: 'Close' });
        expect(close).toHaveAttribute('data-canonical-operation', NOTIFICATION_CENTER_CLOSE_OPERATION_ID);
        expect(NOTIFICATION_CENTER_CLOSE_OPERATION_ID).toBe('AIMW-EMAI-77C637E3E3');

        fireEvent.click(close);

        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        expect(screen.queryByLabelText('Notification center', { selector: 'section' })).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][1]?.method).toBeUndefined();
    });

    it('closes immediately while the authoritative read is still loading', async () => {
        let resolveFetch: (response: Response) => void = () => undefined;
        const fetchMock = vi.fn().mockImplementation(() => new Promise<Response>((resolve) => {
            resolveFetch = resolve;
        }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        const trigger = await screen.findByRole('button', { name: 'Notification center' });
        fireEvent.click(trigger);

        expect(await screen.findByRole('status')).toHaveTextContent('Loading notifications');

        const close = screen.getByRole('button', { name: 'Close' });
        expect(close).toBeEnabled();
        fireEvent.click(close);

        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        expect(screen.queryByRole('button', { name: 'Close' })).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        resolveFetch(new Response(JSON.stringify({ data: [] }), {
            status: 200,
            headers: { 'content-type': 'application/json' },
        }));

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    });

    it('fails closed when notification access is unavailable', async () => {
        vi.stubGlobal('fetch', vi.fn());

        const first = renderControl(context({ permissions: ['tenant.view'] }));
        await waitFor(() => expect(screen.queryByRole('button', { name: 'Close' })).not.toBeInTheDocument());
        first.unmount();

        renderControl(context({ api: {} }));
        await waitFor(() => expect(screen.queryByRole('button', { name: 'Close' })).not.toBeInTheDocument());
    });
});
