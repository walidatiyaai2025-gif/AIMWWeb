import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_SET_VIOLET_OPERATION_ID,
    SETTINGS_THEME_STORAGE_KEY,
    SettingsAccentThemeVioletControl,
} from '../settings-accent-theme-violet-control';

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

describe('AIMW-CONT-7D83B09470 Settings SetViolet', () => {
    it('applies and persists the canonical violet palette without HTTP mutation', async () => {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'gold');
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        const user = userEvent.setup();

        render(
            <LocaleProvider>
                <SettingsAccentThemeVioletControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        const button = screen.getByRole('button', { name: 'Violet' });
        expect(button).toHaveAttribute('data-canonical-operation', SETTINGS_SET_VIOLET_OPERATION_ID);
        expect(SETTINGS_SET_VIOLET_OPERATION_ID).toBe('AIMW-CONT-7D83B09470');
        expect(button).toHaveAttribute('aria-pressed', 'false');

        await user.click(button);

        expect(window.localStorage.getItem('aiwm-color-theme')).toBe('violet');
        expect(document.documentElement.dataset.theme).toBe('violet');
        expect(button).toHaveAttribute('aria-pressed', 'true');
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('restores the authoritative persisted violet selection', () => {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'violet');

        render(
            <LocaleProvider>
                <SettingsAccentThemeVioletControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        expect(screen.getByRole('button', { name: 'Violet' })).toHaveAttribute('aria-pressed', 'true');
    });

    it('fails closed without tenant.view and leaves theme state untouched', () => {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'gold');

        render(
            <LocaleProvider>
                <SettingsAccentThemeVioletControl context={context([])} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: 'Violet' })).not.toBeInTheDocument();
        expect(window.localStorage.getItem(SETTINGS_THEME_STORAGE_KEY)).toBe('gold');
        expect(document.documentElement.dataset.theme).toBeUndefined();
    });
});
