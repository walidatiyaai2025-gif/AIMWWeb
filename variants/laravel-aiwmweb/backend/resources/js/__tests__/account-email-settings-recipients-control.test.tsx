import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    ACCOUNT_EMAIL_SAVE_EDIT_OPERATION_ID,
    AccountEmailSettingsRecipientsControl,
} from '../account-email-settings-recipients-control';

const context = (permissions: string[] = ['tenant.manage'], slug = 'alpha'): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

function renderControl(value = context()) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <AccountEmailSettingsRecipientsControl context={value} />
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('AIMW-EMAI-10F3C44369 account recipient SaveEdit', () => {
    it('reads authoritative recipients then PATCHes the exact tenant and recipient and re-reads', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ id: 7, email_address: 'old@example.test', display_name: 'Old', is_enabled: true }],
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                id: 7, email_address: 'new@example.test', display_name: 'Updated', is_enabled: false,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ id: 7, email_address: 'new@example.test', display_name: 'Updated', is_enabled: false }],
            }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl(context(['tenant.manage'], 'alpha/../beta'));

        const email = await screen.findByLabelText('Recipient email 7');
        fireEvent.change(email, { target: { value: 'new@example.test' } });
        fireEvent.change(screen.getByLabelText('Recipient display name 7'), { target: { value: 'Updated' } });
        fireEvent.click(screen.getByLabelText('Recipient enabled 7'));

        const save = screen.getByRole('button', { name: 'Save' });
        expect(ACCOUNT_EMAIL_SAVE_EDIT_OPERATION_ID).toBe('AIMW-EMAI-10F3C44369');
        expect(save).toHaveAttribute('data-canonical-operation', ACCOUNT_EMAIL_SAVE_EDIT_OPERATION_ID);
        expect(save).toHaveAttribute('data-source-handler', 'SaveEditClickedAsync');
        fireEvent.click(save);

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));
        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/v1/tenants/alpha%2F..%2Fbeta/email/recipients');
        expect(fetchMock.mock.calls[1]?.[0]).toBe('/api/v1/tenants/alpha%2F..%2Fbeta/email/recipients/7');
        expect(fetchMock.mock.calls[1]?.[1]).toMatchObject({ method: 'PATCH' });
        expect(JSON.parse(String(fetchMock.mock.calls[1]?.[1]?.body))).toEqual({
            email_address: 'new@example.test',
            display_name: 'Updated',
            is_enabled: false,
        });
        expect(await screen.findByDisplayValue('new@example.test')).toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent('Dashboard recipient updated.');
    });

    it('fails closed without tenant.manage and makes no read or mutation request', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        renderControl(context(['tenant.view']));

        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('renders truthful empty and error states without synthesizing recipients', async () => {
        const emptyFetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: [] }), {
            status: 200,
            headers: { 'content-type': 'application/json' },
        }));
        vi.stubGlobal('fetch', emptyFetch);
        const empty = renderControl();
        expect(await screen.findByTestId('account-email-recipients-empty')).toHaveTextContent('No dashboard recipients are stored.');
        empty.unmount();

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ message: 'down' }), {
            status: 500,
            headers: { 'content-type': 'application/json' },
        })));
        renderControl();
        expect(await screen.findByRole('alert')).toBeInTheDocument();
    });
});
