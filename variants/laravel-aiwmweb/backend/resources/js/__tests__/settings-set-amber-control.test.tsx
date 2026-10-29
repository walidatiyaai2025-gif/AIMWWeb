import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_SET_AMBER_OPERATION_ID,
    SETTINGS_THEME_STORAGE_KEY,
    SettingsSetAmberControl,
} from '../settings-set-amber-control';

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

describe('AIMW-CONT-CF4BE4EC3B Settings SetAmber', () => {
    it('applies and persists amber immediately without an HTTP mutation', async () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        const user = userEvent.setup();

        render(
            <LocaleProvider>
                <SettingsSetAmberControl context={context()} />
            </LocaleProvider>,
        );

        const amber = screen.getByRole('button', { name: 'Amber' });
        expect(SETTINGS_SET_AMBER_OPERATION_ID).toBe('AIMW-CONT-CF4BE4EC3B');
        expect(amber).toHaveAttribute('data-canonical-operation', SETTINGS_SET_AMBER_OPERATION_ID);
        expect(amber).toHaveAttribute('data-source-handler', 'SetAmber');
        expect(amber).toHaveAttribute('aria-pressed', 'false');

        await user.click(amber);

        expect(window.localStorage.getItem(SETTINGS_THEME_STORAGE_KEY)).toBe('amber');
        expect(document.documentElement.dataset.theme).toBe('amber');
        expect(amber).toHaveAttribute('aria-pressed', 'true');
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('authoritatively renders a persisted amber selection', () => {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'amber');
        document.documentElement.dataset.theme = 'amber';

        render(
            <LocaleProvider>
                <SettingsSetAmberControl context={context()} />
            </LocaleProvider>,
        );

        expect(screen.getByRole('button', { name: 'Amber' })).toHaveAttribute('aria-pressed', 'true');
    });

    it('fails closed when the tenant settings surface is not authorized', () => {
        render(
            <LocaleProvider>
                <SettingsSetAmberControl context={context([])} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: 'Amber' })).not.toBeInTheDocument();
    });
});
