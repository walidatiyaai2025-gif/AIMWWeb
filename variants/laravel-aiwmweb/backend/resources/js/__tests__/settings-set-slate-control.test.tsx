import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_SET_SLATE_OPERATION_ID,
    SETTINGS_THEME_STORAGE_KEY,
    SettingsSetSlateControl,
} from '../settings-set-slate-control';

function context(permissions: string[] = ['tenant.view']): FrontendContext {
    return {
        user: { id: 18, name: 'Alpha User', email: 'alpha@example.test' },
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
    delete document.documentElement.dataset.theme;
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('AIMW-CONT-6B6790BE84 Settings SetSlate', () => {
    it('applies and persists slate immediately without an HTTP mutation', async () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        const user = userEvent.setup();

        render(
            <LocaleProvider>
                <SettingsSetSlateControl context={context()} />
            </LocaleProvider>,
        );

        const slate = screen.getByRole('button', { name: 'Slate' });
        expect(SETTINGS_SET_SLATE_OPERATION_ID).toBe('AIMW-CONT-6B6790BE84');
        expect(slate).toHaveAttribute('data-canonical-operation', SETTINGS_SET_SLATE_OPERATION_ID);
        expect(slate).toHaveAttribute('data-source-handler', 'SetSlate');
        expect(slate).toHaveAttribute('aria-pressed', 'false');

        await user.click(slate);

        expect(window.localStorage.getItem(SETTINGS_THEME_STORAGE_KEY)).toBe('slate');
        expect(document.documentElement.dataset.theme).toBe('slate');
        expect(slate).toHaveAttribute('aria-pressed', 'true');
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('authoritatively renders a persisted slate selection', () => {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'slate');
        document.documentElement.dataset.theme = 'slate';

        render(
            <LocaleProvider>
                <SettingsSetSlateControl context={context()} />
            </LocaleProvider>,
        );

        expect(screen.getByRole('button', { name: 'Slate' })).toHaveAttribute('aria-pressed', 'true');
    });

    it('tracks theme changes applied by the shared shell contract', async () => {
        const user = userEvent.setup();
        render(
            <LocaleProvider>
                <SettingsSetSlateControl context={context()} />
            </LocaleProvider>,
        );

        const slate = screen.getByRole('button', { name: 'Slate' });
        await user.click(slate);
        expect(slate).toHaveAttribute('aria-pressed', 'true');

        document.documentElement.dataset.theme = 'gold';

        await vi.waitFor(() => {
            expect(slate).toHaveAttribute('aria-pressed', 'false');
        });
    });

    it('fails closed when the tenant settings surface is not authorized', () => {
        render(
            <LocaleProvider>
                <SettingsSetSlateControl context={context([])} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: 'Slate' })).not.toBeInTheDocument();
    });
});
