import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { AccountEmailSettingsSummary } from '../account-email-settings-summary';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (permissions: string[], slug = 'alpha'): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

const renderSummary = (value: FrontendContext) => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <AccountEmailSettingsSummary context={value} />
            </LocaleProvider>
        </QueryClientProvider>,
    );
};

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    window.localStorage.clear();
});

describe('account email settings destination', () => {
    it('fails closed before requesting configuration when tenant.manage is missing', () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch');
        renderSummary(context(['tenant.view', 'billing.view']));

        expect(screen.getByText('Permission required')).toBeInTheDocument();
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('reads the same-tenant authoritative configuration and never renders a returned secret field', async () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
            ok: true,
            status: 200,
            headers: new Headers({ 'content-type': 'application/json' }),
            json: async () => ({
                configuration_key: 'default',
                configured: true,
                enabled: true,
                transport: 'smtp',
                host: 'smtp.example.test',
                port: 587,
                encryption: 'tls',
                from_name: 'Alpha Mail',
                from_address: 'mail@example.test',
                reply_to: 'reply@example.test',
                has_secret: true,
                secret: 'must-not-render',
            }),
            text: async () => '',
        } as Response);

        renderSummary(context(['tenant.view', 'tenant.manage', 'billing.view'], 'alpha/../beta'));

        await waitFor(() => expect(screen.getByText('smtp.example.test')).toBeInTheDocument());
        expect(fetchSpy).toHaveBeenCalledTimes(1);
        expect(fetchSpy.mock.calls[0]?.[0]).toBe('/api/v1/tenants/alpha%2F..%2Fbeta/email/configuration');
        expect(screen.getByText('Stored securely')).toBeInTheDocument();
        expect(screen.queryByText('must-not-render')).not.toBeInTheDocument();
    });
});
