// Parity materialization is complete; this comment retriggers exact-head PR validation.
import React from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    NOTIFICATION_CENTER_VIEW_ALL_OPERATION_ID,
    NotificationCenterToggleControl,
} from '../notification-center-toggle-control';

function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'notifications.view'],
        connectors: [],
        capabilities: {},
        api: { notifications: '/api/v1/tenants/alpha/notifications' },
        actions: {},
    };
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

describe('AIMW-EMAI-470599356B NotificationCenter view all', () => {
    it('navigates to the tenant-scoped inbox and closes the popover without another read', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            data: [{ id: 1, title: 'Authoritative notification', is_read: false }],
        }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        render(
            <MemoryRouter>
                <LocaleProvider>
                    <NotificationCenterToggleControl context={context()} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        const trigger = await screen.findByRole('button', { name: 'Notification center' });
        fireEvent.click(trigger);

        expect(await screen.findByText('Authoritative notification')).toBeInTheDocument();

        const viewAll = screen.getByRole('link', { name: 'View all notifications' });
        expect(viewAll).toHaveAttribute('data-canonical-operation', NOTIFICATION_CENTER_VIEW_ALL_OPERATION_ID);
        expect(NOTIFICATION_CENTER_VIEW_ALL_OPERATION_ID).toBe('AIMW-EMAI-470599356B');
        expect(viewAll).toHaveAttribute('href', '/tenants/alpha/notifications');

        fireEvent.click(viewAll);

        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        expect(screen.queryByLabelText('Notification center', { selector: 'section' })).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][1]?.method).toBeUndefined();
    });
});
