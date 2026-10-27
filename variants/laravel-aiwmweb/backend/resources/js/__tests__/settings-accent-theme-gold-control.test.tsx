import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_SET_GOLD_OPERATION_ID,
    SETTINGS_THEME_STORAGE_KEY,
    SettingsAccentThemeGoldControl,
} from '../settings-accent-theme-gold-control';

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

describe('AIMW-CONT-69B5134C7E Settings SetGold', () => {
    it('applies and persists the canonical gold palette without HTTP mutation', async () => {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'emerald');
        document.documentElement.dataset.theme = 'emerald';
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        const user = userEvent.setup();

        render(
            <LocaleProvider>
                <SettingsAccentThemeGoldControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        const button = screen.getByRole('button', { name: 'Gold' });
        expect(button).toHaveAttribute('data-canonical-operation', SETTINGS_SET_GOLD_OPERATION_ID);
        expect(SETTINGS_SET_GOLD_OPERATION_ID).toBe('AIMW-CONT-69B5134C7E');
        expect(button).toHaveAttribute('aria-pressed', 'false');

        await user.click(button);

        expect(window.localStorage.getItem('aiwm-color-theme')).toBe('gold');
        expect(document.documentElement.dataset.theme).toBeUndefined();
        expect(button).toHaveAttribute('aria-pressed', 'true');
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('restores the authoritative default gold selection when storage is empty', () => {
        render(
            <LocaleProvider>
                <SettingsAccentThemeGoldControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        expect(screen.getByRole('button', { name: 'Gold' })).toHaveAttribute('aria-pressed', 'true');
    });

    it('restores an explicitly persisted gold selection', () => {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'gold');

        render(
            <LocaleProvider>
                <SettingsAccentThemeGoldControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        expect(screen.getByRole('button', { name: 'Gold' })).toHaveAttribute('aria-pressed', 'true');
    });

    it('fails closed without tenant.view and leaves theme state untouched', () => {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'emerald');
        document.documentElement.dataset.theme = 'emerald';

        render(
            <LocaleProvider>
                <SettingsAccentThemeGoldControl context={context([])} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: 'Gold' })).not.toBeInTheDocument();
        expect(window.localStorage.getItem(SETTINGS_THEME_STORAGE_KEY)).toBe('emerald');
        expect(document.documentElement.dataset.theme).toBe('emerald');
    });
});
