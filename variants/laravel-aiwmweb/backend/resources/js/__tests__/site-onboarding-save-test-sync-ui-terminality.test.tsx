import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITE_ONBOARDING_NEXT_OPERATION_ID,
    SITE_ONBOARDING_PREVIOUS_OPERATION_ID,
    SITE_ONBOARDING_SAVE_TEST_SYNC_OPERATION_ID,
    SiteOnboardingSaveTestSyncControl,
} from '../site-onboarding-save-test-sync-control';

function context(permissions: string[] = ['tenant.view', 'sites.manage']): FrontendContext {
    return {
        user: { id: 7, name: 'Operator', email: 'operator@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions,
        connectors: [],
        capabilities: {},
        api: { sites: '/api/tenants/alpha/sites' },
        actions: {},
    };
}

function renderControl(permissions?: string[]) {
    const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <SiteOnboardingSaveTestSyncControl context={context(permissions)} />
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

function completeProfileStep(name: string, url: string): void {
    fireEvent.change(screen.getByLabelText('Site name'), { target: { value: name } });
    fireEvent.change(screen.getByLabelText('Site URL'), { target: { value: url } });
    const continueButton = screen.getByRole('button', { name: 'Continue' });
    expect(continueButton).toHaveAttribute('data-canonical-operation', SITE_ONBOARDING_NEXT_OPERATION_ID);
    fireEvent.click(continueButton);
}

function completeCredentialStep(username: string, password: string): HTMLInputElement {
    fireEvent.change(screen.getByLabelText('WordPress username'), { target: { value: username } });
    const secret = screen.getByLabelText('Application Password') as HTMLInputElement;
    fireEvent.change(secret, { target: { value: password } });
    const continueButton = screen.getByRole('button', { name: 'Continue' });
    expect(continueButton).toHaveAttribute('data-canonical-operation', SITE_ONBOARDING_NEXT_OPERATION_ID);
    fireEvent.click(continueButton);
    return secret;
}

afterEach(() => {
    document.querySelector('meta[name="csrf-token"]')?.remove();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('AIMW-BILL-5DE86937F7 onboarding NextAsync browser proof', () => {
    it('validates each source step before advancing and never calls the onboarding API', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(screen.getByRole('alert')).toHaveTextContent('Site name is required.');
        expect(screen.getByText('Step 1 of 3')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Site name'), { target: { value: 'Alpha Site' } });
        fireEvent.change(screen.getByLabelText('Site URL'), { target: { value: 'ftp://alpha.example.test' } });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(screen.getByRole('alert')).toHaveTextContent('Enter a valid URL starting with http or https.');
        expect(screen.getByText('Step 1 of 3')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Site URL'), { target: { value: 'https://alpha.example.test' } });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(screen.getByText('Step 2 of 3')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(screen.getByRole('alert')).toHaveTextContent('WordPress username is required.');

        fireEvent.change(screen.getByLabelText('WordPress username'), { target: { value: 'wp-admin' } });
        fireEvent.change(screen.getByLabelText('Application Password'), { target: { value: 'short' } });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(screen.getByRole('alert')).toHaveTextContent('Application Password is required and must contain at least 8 characters.');

        fireEvent.change(screen.getByLabelText('Application Password'), { target: { value: 'application-secret-123' } });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        expect(screen.getByText('Step 3 of 3')).toBeInTheDocument();
        expect(screen.getByText('Alpha Site')).toBeInTheDocument();
        expect(screen.getByText('https://alpha.example.test')).toBeInTheDocument();
        expect(screen.getByText('wp-admin')).toBeInTheDocument();
        expect(screen.queryByText('application-secret-123')).not.toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('fails closed when sites.manage is absent', () => {
        renderControl(['tenant.view']);
        expect(screen.queryByText('SITE ONBOARDING')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Continue' })).toBeNull();
    });
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
        completeProfileStep('Alpha Site', 'https://alpha.example.test');
        completeCredentialStep('wp-admin', 'application-secret-123');

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

        expect(screen.queryByText('application-secret-123')).not.toBeInTheDocument();
        expect(await screen.findByText('verified / healthy')).toBeInTheDocument();
        expect(screen.getByText('Sync: queued')).toBeInTheDocument();

        // A successful onboarding is terminal: do not reopen a credential form or expose the secret.
        expect(screen.queryByRole('button', { name: 'Previous' })).toBeNull();
        expect(screen.queryByLabelText('Application Password')).toBeNull();
        expect(screen.queryByText('application-secret-123')).not.toBeInTheDocument();
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
        completeProfileStep('Retry Site', 'https://retry.example.test');
        const secret = completeCredentialStep('wp-admin', 'first-secret-value');
        fireEvent.click(screen.getByRole('button', { name: 'Save, test and synchronize' }));

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(JSON.parse(String((fetchMock.mock.calls[0]?.[1] as RequestInit).body))).toEqual({
            name: 'Retry Site',
            url: 'https://retry.example.test',
            username: 'wp-admin',
            application_password: 'first-secret-value',
        });
        expect(await screen.findByRole('alert')).toHaveTextContent('WordPress connection test failed');

        fireEvent.click(screen.getByRole('button', { name: 'Previous' }));
        expect(screen.getByLabelText('Application Password')).toHaveValue('');
        fireEvent.change(screen.getByLabelText('Application Password'), { target: { value: 'second-secret-value' } });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
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
        expect(await screen.findByText('verified / healthy')).toBeInTheDocument();
    });
});

describe('AIMW-SYNC-C1B6FA6677 PreviousAsync browser parity', () => {
    it('navigates backward from review to credentials and profile without API calls or losing entered values', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        renderControl();
        expect(screen.queryByRole('button', { name: 'Previous' })).toBeNull();
        completeProfileStep('Previous Site', 'https://previous.example.test');
        const previousFromCredentials = screen.getByRole('button', { name: 'Previous' });
        expect(previousFromCredentials).toHaveAttribute('data-canonical-operation', SITE_ONBOARDING_PREVIOUS_OPERATION_ID);
        completeCredentialStep('wp-admin', 'password-do-not-log');
        const previousFromReview = screen.getByRole('button', { name: 'Previous' });
        expect(previousFromReview).toHaveAttribute('data-canonical-operation', SITE_ONBOARDING_PREVIOUS_OPERATION_ID);
        fireEvent.click(previousFromReview);
        expect(screen.getByText('Step 2 of 3')).toBeInTheDocument();
        expect(screen.getByLabelText('WordPress username')).toHaveValue('wp-admin');
        expect(screen.getByLabelText('Application Password')).toHaveValue('password-do-not-log');
        fireEvent.click(screen.getByRole('button', { name: 'Previous' }));
        expect(screen.getByText('Step 1 of 3')).toBeInTheDocument();
        expect(screen.getByLabelText('Site name')).toHaveValue('Previous Site');
        expect(screen.getByLabelText('Site URL')).toHaveValue('https://previous.example.test');
        expect(screen.queryByRole('button', { name: 'Previous' })).toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('hides previous after server-confirmed onboarding completion', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
            operation_id: SITE_ONBOARDING_SAVE_TEST_SYNC_OPERATION_ID,
            site: { id: 27, name: 'Saved', url: 'https://saved.example.test',
                connection_status: 'verified', health_state: 'healthy' },
            credential_configured: true,
            sync: null,
            idempotent_replay: false,
        }), { status: 202, headers: { 'content-type': 'application/json' } })));
        renderControl();
        completeProfileStep('Saved', 'https://saved.example.test');
        completeCredentialStep('wp-admin', 'application-secret-123');
        expect(screen.getByRole('button', { name: 'Previous' })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Save, test and synchronize' }));
        expect(await screen.findByText('verified / healthy')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Previous' })).toBeNull();
    });

    it('does not show previous navigation to operators denied sites.manage', () => {
        renderControl(['tenant.view']);
        expect(screen.queryByRole('button', { name: 'Previous' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Continue' })).toBeNull();
    });
});
