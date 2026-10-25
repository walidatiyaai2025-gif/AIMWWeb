import React from 'react';
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_SET_GOLD_OPERATION_ID,
    SETTINGS_THEME_STORAGE_KEY,
    SettingsSetGoldControl,
} from '../settings-set-gold-control';

function context(permissions: string[] = ['tenant.view']): FrontendContext {
    return {
        user: { id: 19, name: 'Alpha User', email: 'alpha@example.test' },
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

describe('AIMW-CONT-69B5134C7E Settings SetGold', () => {
    it('restores the canonical default palette without an HTTP mutation', async () => {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'emerald');
        document.documentElement.dataset.theme = 'emerald';
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        const user = userEvent.setup();

        render(
            <LocaleProvider>
                <SettingsSetGoldControl context={context()} />
            </LocaleProvider>,
        );

        const gold = screen.getByRole('button', { name: 'Gold' });
        expect(SETTINGS_SET_GOLD_OPERATION_ID).toBe('AIMW-CONT-69B5134C7E');
        expect(gold).toHaveAttribute('data-canonical-operation', SETTINGS_SET_GOLD_OPERATION_ID);
        expect(gold).toHaveAttribute('data-source-handler', 'SetGold');
        expect(gold).toHaveAttribute('aria-pressed', 'false');

        await user.click(gold);

        expect(window.localStorage.getItem(SETTINGS_THEME_STORAGE_KEY)).toBe('gold');
        expect(document.documentElement).not.toHaveAttribute('data-theme');
        expect(gold).toHaveAttribute('aria-pressed', 'true');
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('authoritatively renders persisted gold and follows shell theme changes', async () => {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'gold');

        render(
            <LocaleProvider>
                <SettingsSetGoldControl context={context()} />
            </LocaleProvider>,
        );

        const gold = screen.getByRole('button', { name: 'Gold' });
        expect(gold).toHaveAttribute('aria-pressed', 'true');

        await act(async () => {
            document.documentElement.dataset.theme = 'slate';
        });
        expect(gold).toHaveAttribute('aria-pressed', 'false');

        await act(async () => {
            window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'gold');
            document.documentElement.removeAttribute('data-theme');
        });
        expect(gold).toHaveAttribute('aria-pressed', 'true');
    });

    it('fails closed without tenant.view and leaves the theme untouched', () => {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'emerald');
        document.documentElement.dataset.theme = 'emerald';

        render(
            <LocaleProvider>
                <SettingsSetGoldControl context={context([])} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: 'Gold' })).not.toBeInTheDocument();
        expect(window.localStorage.getItem(SETTINGS_THEME_STORAGE_KEY)).toBe('emerald');
        expect(document.documentElement.dataset.theme).toBe('emerald');
    });
});
