import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    ACCOUNT_EMAIL_CLEAR_PASSWORD_OPERATION_ID,
    AccountEmailSettingsClearPasswordControl,
} from '../account-email-settings-clear-password-control';
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

const renderControl = (value: FrontendContext, hasSecret = true, onCleared = vi.fn()) => {
    const view = render(
        <LocaleProvider>
            <AccountEmailSettingsClearPasswordControl
                context={value}
                hasSecret={hasSecret}
                onCleared={onCleared}
            />
        </LocaleProvider>,
    );

    return { ...view, onCleared };
};

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    window.localStorage.clear();
});

describe('AIMW-EMAI-54E7EEFB15 account email clear password', () => {
    it('deletes only the saved secret through the tenant-qualified endpoint', async () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
            ok: true,
            status: 200,
            headers: new Headers({ 'content-type': 'application/json' }),
            json: async () => ({
                configuration_key: 'default',
                configured: true,
                has_secret: false,
                host: 'smtp.alpha.test',
                port: 587,
                from_address: 'from@alpha.test',
                enabled: true,
            }),
            text: async () => '',
        } as Response);
        const { onCleared } = renderControl(
            context(['tenant.manage'], 'alpha/../beta'),
            true,
        );

        const button = screen.getByRole('button', { name: 'Clear saved password' });
        expect(button).toHaveAttribute('data-canonical-operation', ACCOUNT_EMAIL_CLEAR_PASSWORD_OPERATION_ID);
        fireEvent.click(button);

        await waitFor(() => expect(fetchSpy).toHaveBeenCalledTimes(1));
        expect(fetchSpy.mock.calls[0]?.[0]).toBe('/api/v1/tenants/alpha%2F..%2Fbeta/email/configuration/secret');
        expect(fetchSpy.mock.calls[0]?.[1]).toMatchObject({ method: 'DELETE' });
        expect(JSON.parse(String(fetchSpy.mock.calls[0]?.[1]?.body))).toEqual({ configuration_key: 'default' });
        await waitFor(() => expect(onCleared).toHaveBeenCalledTimes(1));
    });

    it('fails closed without tenant.manage and stays hidden when no secret exists', () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch');
        const first = renderControl(context(['tenant.view']), true);
        expect(screen.queryByRole('button', { name: 'Clear saved password' })).not.toBeInTheDocument();
        first.unmount();

        renderControl(context(['tenant.manage']), false);
        expect(screen.queryByRole('button', { name: 'Clear saved password' })).not.toBeInTheDocument();
        expect(fetchSpy).not.toHaveBeenCalled();
    });
});
