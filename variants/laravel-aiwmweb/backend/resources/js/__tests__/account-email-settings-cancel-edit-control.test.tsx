import React from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    ACCOUNT_EMAIL_CANCEL_EDIT_OPERATION_ID,
    AccountEmailSettingsProfileForm,
    type AccountMailConfiguration,
} from '../account-email-settings-profile-form';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (permissions: string[]): FrontendContext => ({
    user: { id: 1, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
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
    host: 'smtp.alpha.test',
    port: 587,
    encryption: 'tls',
    username: 'mailer@alpha.test',
    from_address: 'from@alpha.test',
    from_name: 'Alpha Mail',
    reply_to: 'reply@alpha.test',
    enabled: true,
    timeout_seconds: 20,
    max_attempts: 4,
};

const renderControl = (permissions = ['tenant.manage']) => render(
    <LocaleProvider>
        <AccountEmailSettingsProfileForm
            context={context(permissions)}
            configuration={configuration}
        />
    </LocaleProvider>,
);

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    window.localStorage.clear();
});

describe('AIMW-EMAI-A9CB66F400 account email CancelEdit adaptation', () => {
    it('restores the latest authoritative draft and performs no request', () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch');
        renderControl();

        fireEvent.change(screen.getByLabelText('SMTP Host'), { target: { value: 'smtp.unsaved.test' } });
        fireEvent.change(screen.getByLabelText('SMTP Port'), { target: { value: '2525' } });
        fireEvent.change(screen.getByLabelText('SMTP username'), { target: { value: 'unsaved@alpha.test' } });
        fireEvent.change(screen.getByLabelText('SMTP password'), { target: { value: 'transient-secret' } });
        fireEvent.change(screen.getByLabelText('From address'), { target: { value: 'unsaved-from@alpha.test' } });
        fireEvent.click(screen.getByLabelText('Enable account outbound email'));

        const cancel = screen.getByRole('button', { name: 'Cancel' });
        expect(cancel).toHaveAttribute('data-canonical-operation', ACCOUNT_EMAIL_CANCEL_EDIT_OPERATION_ID);
        expect(cancel).toHaveAttribute('data-source-handler', 'CancelEditClicked');

        fireEvent.click(cancel);

        expect(screen.getByLabelText('SMTP Host')).toHaveValue('smtp.alpha.test');
        expect(screen.getByLabelText('SMTP Port')).toHaveValue(587);
        expect(screen.getByLabelText('SMTP username')).toHaveValue('mailer@alpha.test');
        expect(screen.getByLabelText('SMTP password')).toHaveValue('');
        expect(screen.getByLabelText('From address')).toHaveValue('from@alpha.test');
        expect(screen.getByLabelText('Enable account outbound email')).toBeChecked();
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('is not exposed without tenant.manage', () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch');
        renderControl(['tenant.view']);

        expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();
        expect(fetchSpy).not.toHaveBeenCalled();
    });
});
