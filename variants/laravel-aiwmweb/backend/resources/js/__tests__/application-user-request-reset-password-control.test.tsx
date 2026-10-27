import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    APPLICATION_USER_REQUEST_RESET_PASSWORD_OPERATION_ID,
    ApplicationUserPasswordResetControl,
} from '../application-user-password-reset-control';

const context: FrontendContext = {
    user: { id: 1, name: 'Owner', email: 'owner@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['tenant.view', 'members.manage'],
    connectors: [],
    capabilities: {},
    api: { 'application-users': '/tenants/alpha/admin/members' },
    actions: {},
};

function json(payload: unknown, status = 200) {
    return new Response(JSON.stringify(payload), {
        status,
        headers: { 'content-type': 'application/json' },
    });
}

function renderControl() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <MemoryRouter initialEntries={['/tenants/alpha/admin/users']}>
                    <ApplicationUserPasswordResetControl context={context} />
                </MemoryRouter>
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('AIMW-SYNC-9ABD7DEDBB ApplicationUsers RequestResetPasswordAsync', () => {
    it('validates locally and opens review without sending a password mutation', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            json({ data: [{ id: 7, status: 'active', user: { id: 70, name: 'Target', email: 'target@example.test' } }] }),
        );
        vi.stubGlobal('fetch', fetchMock);
        renderControl();

        const userSelect = await screen.findByLabelText('User');
        await screen.findByRole('option', { name: 'Target — target@example.test' });
        fireEvent.change(userSelect, { target: { value: '7' } });

        const review = screen.getByRole('button', { name: 'Review reset' });
        expect(APPLICATION_USER_REQUEST_RESET_PASSWORD_OPERATION_ID).toBe('AIMW-SYNC-9ABD7DEDBB');
        expect(review).toHaveAttribute('data-canonical-operation', APPLICATION_USER_REQUEST_RESET_PASSWORD_OPERATION_ID);

        fireEvent.click(review);
        expect(await screen.findByRole('alert')).toHaveTextContent('A new password is required.');
        expect(fetchMock.mock.calls.filter((call) => (call[1] as RequestInit | undefined)?.method === 'POST')).toHaveLength(0);

        fireEvent.change(screen.getByLabelText('New password'), { target: { value: 'StrongPass9' } });
        fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: 'StrongPass9' } });
        fireEvent.click(review);

        expect(await screen.findByTestId('application-user-reset-review')).toBeInTheDocument();
        expect(screen.getByText('Confirm password reset')).toBeInTheDocument();
        expect(fetchMock.mock.calls.filter((call) => (call[1] as RequestInit | undefined)?.method === 'POST')).toHaveLength(0);
    });

    it('requires matching password confirmation before opening review', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
            json({ data: [{ id: 7, status: 'active', user: { id: 70, name: 'Target', email: 'target@example.test' } }] }),
        ));
        renderControl();

        const userSelect = await screen.findByLabelText('User');
        await screen.findByRole('option', { name: 'Target — target@example.test' });
        fireEvent.change(userSelect, { target: { value: '7' } });
        fireEvent.change(screen.getByLabelText('New password'), { target: { value: 'StrongPass9' } });
        fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: 'DifferentPass9' } });
        fireEvent.click(screen.getByRole('button', { name: 'Review reset' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('New password confirmation does not match.');
        expect(screen.queryByTestId('application-user-reset-review')).not.toBeInTheDocument();
    });

    it('keeps the actual reset behind the separate confirmation step', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(json({ data: [{ id: 7, status: 'active', user: { id: 70, name: 'Target', email: 'target@example.test' } }] }))
            .mockResolvedValueOnce(json({
                membership_id: 7,
                user: { id: 70, name: 'Target', email: 'target@example.test' },
                password_changed: true,
                revoked_sessions: 1,
            }))
            .mockResolvedValueOnce(json({ data: [{ id: 7, status: 'active', user: { id: 70, name: 'Target', email: 'target@example.test' } }] }))
            .mockResolvedValue(json({ data: [{ id: 7, status: 'active', user: { id: 70, name: 'Target', email: 'target@example.test' } }] }));
        vi.stubGlobal('fetch', fetchMock);
        renderControl();

        const userSelect = await screen.findByLabelText('User');
        await screen.findByRole('option', { name: 'Target — target@example.test' });
        fireEvent.change(userSelect, { target: { value: '7' } });
        fireEvent.change(screen.getByLabelText('New password'), { target: { value: 'StrongPass9' } });
        fireEvent.change(screen.getByLabelText('Confirm password'), { target: { value: 'StrongPass9' } });
        fireEvent.click(screen.getByRole('button', { name: 'Review reset' }));

        await screen.findByTestId('application-user-reset-review');
        expect(fetchMock.mock.calls.filter((call) => (call[1] as RequestInit | undefined)?.method === 'POST')).toHaveLength(0);

        fireEvent.change(screen.getByLabelText('Type the target email to confirm'), { target: { value: 'target@example.test' } });
        fireEvent.click(screen.getByRole('button', { name: 'Reset password' }));

        await waitFor(() => {
            expect(fetchMock.mock.calls.filter((call) => (call[1] as RequestInit | undefined)?.method === 'POST')).toHaveLength(1);
        });
    });
});
