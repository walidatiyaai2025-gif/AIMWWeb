import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITE_ONBOARDING_SAVE_TEST_SYNC_OPERATION_ID,
    SiteOnboardingSaveTestSyncControl,
} from '../site-onboarding-save-test-sync-control';

function context(): FrontendContext {
    return {
        user: { id: 7, name: 'Operator', email: 'operator@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.manage'],
        connectors: [],
        capabilities: {},
        api: { sites: '/api/tenants/alpha/sites' },
        actions: {},
    };
}

function renderControl() {
    const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <SiteOnboardingSaveTestSyncControl context={context()} />
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    document.querySelector('meta[name="csrf-token"]')?.remove();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('AIMW-BILL-2EF6B8A27A onboarding browser proof', () => {
    it('submits CSRF + idempotency, clears the Application Password, and never renders the secret', async () => {
        const meta = document.createElement('meta');
        meta.name = 'csrf-token';
        meta.content = 'csrf-proof';
        document.head.appendChild(meta);

        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            operation_id: SITE_ONBOARDING_SAVE_TEST_SYNC_OPERATION_ID,
            site: {
                id: 17,
                name: 'Alpha Site',
                url: 'https://alpha.example.test',
                connection_status: 'verified',
                health_state: 'healthy',
            },
            credential_configured: true,
            sync: { id: 41, status: 'queued', processed: 0, failure: null },
            idempotent_replay: false,
        }), { status: 202, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);
        vi.stubGlobal('crypto', { randomUUID: () => '11111111-1111-4111-8111-111111111111' });

        renderControl();
        const inputs = screen.getAllByRole('textbox');
        fireEvent.change(inputs[0], { target: { value: 'Alpha Site' } });
        fireEvent.change(inputs[1], { target: { value: 'https://alpha.example.test' } });
        fireEvent.change(inputs[2], { target: { value: 'wp-admin' } });
        const secret = screen.getByLabelText('Application Password');
        fireEvent.change(secret, { target: { value: 'application-secret-123' } });

        const button = screen.getByRole('button', { name: 'Save, test and synchronize' });
        expect(button).toHaveAttribute('data-canonical-operation', SITE_ONBOARDING_SAVE_TEST_SYNC_OPERATION_ID);
        fireEvent.click(button);

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        const init = fetchMock.mock.calls[0]?.[1] as RequestInit;
        const headers = new Headers(init.headers);
        expect(headers.get('X-CSRF-TOKEN')).toBe('csrf-proof');
        expect(headers.get('Idempotency-Key')).toBe('11111111-1111-4111-8111-111111111111');
        expect(JSON.parse(String(init.body))).toEqual({
            name: 'Alpha Site',
            url: 'https://alpha.example.test',
            username: 'wp-admin',
            application_password: 'application-secret-123',
        });

        await waitFor(() => expect(secret).toHaveValue(''));
        expect(screen.queryByText('application-secret-123')).not.toBeInTheDocument();
        expect(await screen.findByText('verified / healthy')).toBeInTheDocument();
        expect(screen.getByText('Sync: queued')).toBeInTheDocument();
    });

    it('retries the failed saved profile only with the server-issued opaque retry token', async () => {
        const uuid = vi.fn()
            .mockReturnValueOnce('22222222-2222-4222-8222-222222222222')
            .mockReturnValueOnce('33333333-3333-4333-8333-333333333333');
        vi.stubGlobal('crypto', { randomUUID: uuid });

        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({
                operation_id: SITE_ONBOARDING_SAVE_TEST_SYNC_OPERATION_ID,
                message: 'WordPress connection test failed. Initial synchronization was not started.',
                site: {
                    id: 23,
                    name: 'Retry Site',
                    url: 'https://retry.example.test',
                    connection_status: 'failed',
                    health_state: 'unhealthy',
                },
                credential_configured: false,
                retry_token: 'opaque-server-issued-retry-token',
                sync: null,
                idempotent_replay: false,
            }), { status: 422, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                operation_id: SITE_ONBOARDING_SAVE_TEST_SYNC_OPERATION_ID,
                site: {
                    id: 23,
                    name: 'Retry Site',
                    url: 'https://retry.example.test',
                    connection_status: 'verified',
                    health_state: 'healthy',
                },
                credential_configured: true,
                sync: { id: 51, status: 'queued', processed: 0, failure: null },
                idempotent_replay: false,
            }), { status: 202, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();
        const inputs = screen.getAllByRole('textbox');
        fireEvent.change(inputs[0], { target: { value: 'Retry Site' } });
        fireEvent.change(inputs[1], { target: { value: 'https://retry.example.test' } });
        fireEvent.change(inputs[2], { target: { value: 'wp-admin' } });
        const secret = screen.getByLabelText('Application Password');
        fireEvent.change(secret, { target: { value: 'first-secret-value' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save, test and synchronize' }));

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(JSON.parse(String((fetchMock.mock.calls[0]?.[1] as RequestInit).body))).toEqual({
            name: 'Retry Site',
            url: 'https://retry.example.test',
            username: 'wp-admin',
            application_password: 'first-secret-value',
        });
        await waitFor(() => expect(secret).toHaveValue(''));
        expect(await screen.findByRole('alert')).toHaveTextContent('WordPress connection test failed');

        fireEvent.change(secret, { target: { value: 'second-secret-value' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save, test and synchronize' }));

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        const secondInit = fetchMock.mock.calls[1]?.[1] as RequestInit;
        expect(new Headers(secondInit.headers).get('Idempotency-Key'))
            .toBe('33333333-3333-4333-8333-333333333333');
        expect(JSON.parse(String(secondInit.body))).toEqual({
            name: 'Retry Site',
            url: 'https://retry.example.test',
            username: 'wp-admin',
            application_password: 'second-secret-value',
            retry_token: 'opaque-server-issued-retry-token',
        });
        await waitFor(() => expect(secret).toHaveValue(''));
        expect(await screen.findByText('verified / healthy')).toBeInTheDocument();
    });
});
