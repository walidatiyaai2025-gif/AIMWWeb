import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_LANGUAGE_TOGGLE_OPERATION_ID,
    SettingsAiProvidersLinkControl,
} from '../settings-ai-providers-link-control';

const context = (permissions: string[]): FrontendContext => ({
    user: { id: 10, name: 'Alpha User', email: 'alpha@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

beforeEach(() => {
    window.localStorage.clear();
    document.documentElement.lang = 'en';
    document.documentElement.dir = 'ltr';
    vi.restoreAllMocks();
});

describe('AIMW-BILL-1234961B6E Settings ToggleLanguage', () => {
    it('toggles en to ar, persists culture, and applies lang/dir immediately without an HTTP mutation', async () => {
        const user = userEvent.setup();
        const fetchSpy = vi.spyOn(window, 'fetch');

        render(
            <LocaleProvider>
                <SettingsAiProvidersLinkControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        const button = screen.getByRole('button', { name: /switch language/i });
        expect(button).toHaveAttribute('data-canonical-operation', SETTINGS_LANGUAGE_TOGGLE_OPERATION_ID);
        expect(SETTINGS_LANGUAGE_TOGGLE_OPERATION_ID).toBe('AIMW-BILL-1234961B6E');

        await user.click(button);

        expect(window.localStorage.getItem('aiwm.locale')).toBe('ar');
        await waitFor(() => {
            expect(document.documentElement).toHaveAttribute('lang', 'ar');
            expect(document.documentElement).toHaveAttribute('dir', 'rtl');
        });
        expect(screen.getByRole('button', { name: 'تبديل اللغة' })).toBeInTheDocument();
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('restores persisted Arabic and toggles back to English deterministically', async () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        const user = userEvent.setup();

        render(
            <LocaleProvider>
                <SettingsAiProvidersLinkControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        await waitFor(() => {
            expect(document.documentElement).toHaveAttribute('lang', 'ar');
            expect(document.documentElement).toHaveAttribute('dir', 'rtl');
        });

        await user.click(screen.getByRole('button', { name: 'تبديل اللغة' }));

        expect(window.localStorage.getItem('aiwm.locale')).toBe('en');
        await waitFor(() => {
            expect(document.documentElement).toHaveAttribute('lang', 'en');
            expect(document.documentElement).toHaveAttribute('dir', 'ltr');
        });
        expect(screen.getByRole('button', { name: /switch language/i })).toBeInTheDocument();
    });

    it('fails closed without tenant.view; the authenticated foreign-tenant/404 and permission/403 boundaries remain server-side', () => {
        render(
            <LocaleProvider>
                <SettingsAiProvidersLinkControl context={context([])} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: /switch language/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'تبديل اللغة' })).not.toBeInTheDocument();
        expect(window.localStorage.getItem('aiwm.locale')).toBeNull();
    });
});
