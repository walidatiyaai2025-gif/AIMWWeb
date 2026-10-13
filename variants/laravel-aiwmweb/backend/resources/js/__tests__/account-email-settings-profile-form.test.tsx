import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    ACCOUNT_EMAIL_SAVE_PROFILE_OPERATION_ID,
    AccountEmailSettingsProfileForm,
    type AccountMailConfiguration,
} from '../account-email-settings-profile-form';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (permissions: string[], slug = 'alpha'): FrontendContext => ({
    user: { id: 1, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

const configuration: AccountMailConfiguration = {
    configuration_key: 'default',
    configured: true,
    has_secret: true,
    transport: 'smtp',
    host: 'smtp.old.test',
    port: 587,
    encryption: 'tls',
    username: 'mailer@alpha.test',
    from_address: 'old@alpha.test',
    from_name: 'Alpha Mail',
    reply_to: 'reply@alpha.test',
    enabled: true,
    timeout_seconds: 20,
    max_attempts: 4,
};

const renderForm = (value = context(['tenant.manage']), onSaved = vi.fn()) => {
    const view = render(
        <LocaleProvider>
            <AccountEmailSettingsProfileForm
                context={value}
                configuration={configuration}
                onSaved={onSaved}
            />
        </LocaleProvider>,
    );
    return { ...view, onSaved };
};

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    window.localStorage.clear();
});

describe('AIMW-EMAI-00CC1272F6 account mail profile save', () => {
    it('saves the authoritative tenant profile without ever pre-filling the stored secret', async () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
            ok: true,
            status: 200,
            headers: new Headers({ 'content-type': 'application/json' }),
            json: async () => ({ ...configuration, host: 'smtp.new.test', from_address: 'new@alpha.test' }),
            text: async () => '',
        } as Response);
        const { onSaved } = renderForm(context(['tenant.manage'], 'alpha/../beta'));

        const password = screen.getByLabelText('SMTP password') as HTMLInputElement;
        expect(password.value).toBe('');

        fireEvent.change(screen.getByLabelText('SMTP Host'), { target: { value: 'smtp.new.test' } });
        fireEvent.change(screen.getByLabelText('From address'), { target: { value: 'new@alpha.test' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save mail profile' }));

        await waitFor(() => expect(fetchSpy).toHaveBeenCalledTimes(1));
        expect(fetchSpy.mock.calls[0]?.[0]).toBe('/api/v1/tenants/alpha%2F..%2Fbeta/email/configuration');
        expect(fetchSpy.mock.calls[0]?.[1]).toMatchObject({ method: 'PUT' });

        const payload = JSON.parse(String(fetchSpy.mock.calls[0]?.[1]?.body));
        expect(payload).toMatchObject({
            configuration_key: 'default',
            transport: 'smtp',
            host: 'smtp.new.test',
            port: 587,
            username: 'mailer@alpha.test',
            secret: null,
            from_address: 'new@alpha.test',
            from_name: 'Alpha Mail',
            reply_to: 'reply@alpha.test',
            enabled: true,
            timeout_seconds: 20,
            max_attempts: 4,
        });
        expect(payload).not.toHaveProperty('clear_secret');
        await waitFor(() => expect(onSaved).toHaveBeenCalledTimes(1));
        expect(screen.getByRole('button', { name: 'Save mail profile' }))
            .toHaveAttribute('data-canonical-operation', ACCOUNT_EMAIL_SAVE_PROFILE_OPERATION_ID);
    });

    it('sends a newly entered secret but never exposes it after the save callback', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue({
            ok: true,
            status: 200,
            headers: new Headers({ 'content-type': 'application/json' }),
            json: async () => ({ ...configuration, has_secret: true }),
            text: async () => '',
        } as Response);
        renderForm();

        const password = screen.getByLabelText('SMTP password') as HTMLInputElement;
        fireEvent.change(password, { target: { value: 'new-secret' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save mail profile' }));

        await waitFor(() => expect(password.value).toBe(''));
        expect(screen.queryByDisplayValue('new-secret')).not.toBeInTheDocument();
    });

    it('fails closed without tenant.manage', () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch');
        renderForm(context(['tenant.view']));

        expect(screen.queryByRole('button', { name: 'Save mail profile' })).not.toBeInTheDocument();
        expect(fetchSpy).not.toHaveBeenCalled();
    });
});
