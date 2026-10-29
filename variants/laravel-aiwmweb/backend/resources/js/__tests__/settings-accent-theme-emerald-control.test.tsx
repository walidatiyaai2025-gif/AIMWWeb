import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_SET_EMERALD_OPERATION_ID,
    SETTINGS_THEME_STORAGE_KEY,
    SettingsAccentThemeEmeraldControl,
} from '../settings-accent-theme-emerald-control';

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
    delete document.documentElement.dataset.theme;
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('AIMW-CONT-566A1899F1 Settings SetEmerald', () => {
    it('applies and persists the canonical emerald palette without HTTP mutation', async () => {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'gold');
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        const user = userEvent.setup();

        render(
            <LocaleProvider>
                <SettingsAccentThemeEmeraldControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        const button = screen.getByRole('button', { name: 'Emerald' });
        expect(button).toHaveAttribute('data-canonical-operation', SETTINGS_SET_EMERALD_OPERATION_ID);
        expect(SETTINGS_SET_EMERALD_OPERATION_ID).toBe('AIMW-CONT-566A1899F1');
        expect(button).toHaveAttribute('aria-pressed', 'false');

        await user.click(button);

        expect(window.localStorage.getItem('aiwm-color-theme')).toBe('emerald');
        expect(document.documentElement.dataset.theme).toBe('emerald');
        expect(button).toHaveAttribute('aria-pressed', 'true');
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('restores the authoritative persisted emerald selection', () => {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'emerald');

        render(
            <LocaleProvider>
                <SettingsAccentThemeEmeraldControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        expect(screen.getByRole('button', { name: 'Emerald' })).toHaveAttribute('aria-pressed', 'true');
    });

    it('fails closed without tenant.view and leaves theme state untouched', () => {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'gold');

        render(
            <LocaleProvider>
                <SettingsAccentThemeEmeraldControl context={context([])} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: 'Emerald' })).not.toBeInTheDocument();
        expect(window.localStorage.getItem(SETTINGS_THEME_STORAGE_KEY)).toBe('gold');
        expect(document.documentElement.dataset.theme).toBeUndefined();
    });
});
