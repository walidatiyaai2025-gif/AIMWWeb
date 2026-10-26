import React from 'react';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_SET_OCEAN_OPERATION_ID,
    SETTINGS_THEME_STORAGE_KEY,
    SettingsAccentThemeControl,
} from '../settings-accent-theme-control';

function context(permissions: string[] = ['tenant.view']): FrontendContext {
    return {
        user: { id: 21, name: 'Alpha User', email: 'alpha@example.test' },
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
    document.documentElement.removeAttribute('data-theme');
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('AIMW-CONT-659BEF8765 Settings SetOcean', () => {
    it('applies and persists the canonical Ocean palette without an HTTP mutation', async () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        const user = userEvent.setup();

        render(
            <LocaleProvider>
                <SettingsAccentThemeControl context={context()} />
            </LocaleProvider>,
        );

        const ocean = screen.getByRole('button', { name: 'Ocean' });
        expect(SETTINGS_SET_OCEAN_OPERATION_ID).toBe('AIMW-CONT-659BEF8765');
        expect(ocean).toHaveAttribute('data-canonical-operation', SETTINGS_SET_OCEAN_OPERATION_ID);
        expect(ocean).toHaveAttribute('data-source-handler', 'SetOcean');
        expect(ocean).toHaveAttribute('aria-pressed', 'false');

        await user.click(ocean);

        expect(window.localStorage.getItem(SETTINGS_THEME_STORAGE_KEY)).toBe('ocean');
        expect(document.documentElement).toHaveAttribute('data-theme', 'ocean');
        expect(ocean).toHaveAttribute('aria-pressed', 'true');
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('renders persisted Ocean authoritatively and follows shell theme changes', async () => {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'ocean');

        render(
            <LocaleProvider>
                <SettingsAccentThemeControl context={context()} />
            </LocaleProvider>,
        );

        const ocean = screen.getByRole('button', { name: 'Ocean' });
        expect(ocean).toHaveAttribute('aria-pressed', 'true');

        act(() => {
            document.documentElement.setAttribute('data-theme', 'rose');
        });
        await waitFor(() => expect(ocean).toHaveAttribute('aria-pressed', 'false'));

        act(() => {
            window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'ocean');
            document.documentElement.setAttribute('data-theme', 'ocean');
        });
        await waitFor(() => expect(ocean).toHaveAttribute('aria-pressed', 'true'));
    });

    it('fails closed without tenant.view and leaves the theme untouched', () => {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'emerald');
        document.documentElement.setAttribute('data-theme', 'emerald');

        render(
            <LocaleProvider>
                <SettingsAccentThemeControl context={context([])} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: 'Ocean' })).not.toBeInTheDocument();
        expect(window.localStorage.getItem(SETTINGS_THEME_STORAGE_KEY)).toBe('emerald');
        expect(document.documentElement).toHaveAttribute('data-theme', 'emerald');
    });
});
