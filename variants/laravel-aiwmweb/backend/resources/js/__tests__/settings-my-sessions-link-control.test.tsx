import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_MY_SESSIONS_LINK_OPERATION_ID,
    SETTINGS_MY_SESSIONS_SOURCE_PATH,
    SettingsMySessionsLinkControl,
} from '../settings-my-sessions-link-control';

function context(permissions: string[]): FrontendContext {
    return {
        user: { id: 10, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions,
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
    };
}

beforeEach(() => {
    window.localStorage.clear();
});

describe(`${SETTINGS_MY_SESSIONS_LINK_OPERATION_ID} Settings My Sessions link`, () => {
    it('renders the exact tenant-qualified settings/sessions alias when both session permissions are present', () => {
        render(
            <LocaleProvider>
                <SettingsMySessionsLinkControl context={context(['sessions.view', 'sessions.manage'])} />
            </LocaleProvider>,
        );

        const link = screen.getByRole('link', { name: /Open my sessions/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/settings/sessions');
        expect(link).toHaveAttribute('data-canonical-operation', SETTINGS_MY_SESSIONS_LINK_OPERATION_ID);
        expect(SETTINGS_MY_SESSIONS_SOURCE_PATH).toBe('/settings/sessions');
        expect(SETTINGS_MY_SESSIONS_LINK_OPERATION_ID).toBe('AIMW-CONT-A6EA54E66D');
    });

    it('allows wildcard permission and fails closed when either destination permission is missing', () => {
        const { rerender } = render(
            <LocaleProvider>
                <SettingsMySessionsLinkControl context={context(['*'])} />
            </LocaleProvider>,
        );
        expect(screen.getByRole('link', { name: /Open my sessions/i })).toBeInTheDocument();

        rerender(
            <LocaleProvider>
                <SettingsMySessionsLinkControl context={context(['sessions.view', 'sessions.manage'])} />
            </LocaleProvider>,
        );
        expect(screen.queryByRole('link', { name: /Open my sessions/i })).not.toBeInTheDocument();

        rerender(
            <LocaleProvider>
                <SettingsMySessionsLinkControl context={context(['sessions.manage'])} />
            </LocaleProvider>,
        );
        expect(screen.queryByRole('link', { name: /Open my sessions/i })).not.toBeInTheDocument();
    });

    it('renders Arabic copy without changing the canonical destination', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        render(
            <LocaleProvider>
                <SettingsMySessionsLinkControl context={context(['sessions.view'])} />
            </LocaleProvider>,
        );

        expect(screen.getByRole('link', { name: 'فتح جلساتي' }))
            .toHaveAttribute('href', '/tenants/alpha/settings/sessions');
    });
});
