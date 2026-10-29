import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_SET_CYAN_OPERATION_ID,
    SETTINGS_THEME_STORAGE_KEY,
    SettingsAccentThemeCyanControl,
} from '../settings-accent-theme-cyan-control';

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

describe('AIMW-CONT-349017BDF5 Settings SetCyan', () => {
    it('applies and persists the canonical cyan palette without HTTP mutation', async () => {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'gold');
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        const user = userEvent.setup();

        render(
            <LocaleProvider>
                <SettingsAccentThemeCyanControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        const button = screen.getByRole('button', { name: 'Cyan' });
        expect(button).toHaveAttribute('data-canonical-operation', SETTINGS_SET_CYAN_OPERATION_ID);
        expect(SETTINGS_SET_CYAN_OPERATION_ID).toBe('AIMW-CONT-349017BDF5');
        expect(button).toHaveAttribute('aria-pressed', 'false');

        await user.click(button);

        expect(window.localStorage.getItem('aiwm-color-theme')).toBe('cyan');
        expect(document.documentElement.dataset.theme).toBe('cyan');
        expect(button).toHaveAttribute('aria-pressed', 'true');
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('restores the authoritative persisted cyan selection', () => {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'cyan');

        render(
            <LocaleProvider>
                <SettingsAccentThemeCyanControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        expect(screen.getByRole('button', { name: 'Cyan' })).toHaveAttribute('aria-pressed', 'true');
    });

    it('fails closed without tenant.view and leaves theme state untouched', () => {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'gold');

        render(
            <LocaleProvider>
                <SettingsAccentThemeCyanControl context={context([])} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: 'Cyan' })).not.toBeInTheDocument();
        expect(window.localStorage.getItem(SETTINGS_THEME_STORAGE_KEY)).toBe('gold');
        expect(document.documentElement.dataset.theme).toBeUndefined();
    });
});
