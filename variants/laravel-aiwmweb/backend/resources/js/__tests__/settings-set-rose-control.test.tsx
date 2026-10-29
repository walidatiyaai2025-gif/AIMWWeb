import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_SET_ROSE_OPERATION_ID,
    SETTINGS_THEME_STORAGE_KEY,
    SettingsSetRoseControl,
} from '../settings-set-rose-control';

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

describe('AIMW-CONT-6700342002 Settings SetRose', () => {
    it('applies and persists rose immediately without an HTTP mutation', async () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        const user = userEvent.setup();

        render(
            <LocaleProvider>
                <SettingsSetRoseControl context={context()} />
            </LocaleProvider>,
        );

        const rose = screen.getByRole('button', { name: 'Rose' });
        expect(SETTINGS_SET_ROSE_OPERATION_ID).toBe('AIMW-CONT-6700342002');
        expect(rose).toHaveAttribute('data-canonical-operation', SETTINGS_SET_ROSE_OPERATION_ID);
        expect(rose).toHaveAttribute('data-source-handler', 'SetRose');
        expect(rose).toHaveAttribute('aria-pressed', 'false');

        await user.click(rose);

        expect(window.localStorage.getItem(SETTINGS_THEME_STORAGE_KEY)).toBe('rose');
        expect(document.documentElement.dataset.theme).toBe('rose');
        expect(rose).toHaveAttribute('aria-pressed', 'true');
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('authoritatively renders a persisted rose selection', () => {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'rose');
        document.documentElement.dataset.theme = 'rose';

        render(
            <LocaleProvider>
                <SettingsSetRoseControl context={context()} />
            </LocaleProvider>,
        );

        expect(screen.getByRole('button', { name: 'Rose' })).toHaveAttribute('aria-pressed', 'true');
    });

    it('tracks theme changes applied by the shared shell contract', async () => {
        const user = userEvent.setup();
        render(
            <LocaleProvider>
                <SettingsSetRoseControl context={context()} />
            </LocaleProvider>,
        );

        const rose = screen.getByRole('button', { name: 'Rose' });
        await user.click(rose);
        expect(rose).toHaveAttribute('aria-pressed', 'true');

        document.documentElement.dataset.theme = 'slate';

        await vi.waitFor(() => {
            expect(rose).toHaveAttribute('aria-pressed', 'false');
        });
    });

    it('renders Arabic copy while preserving the exact operation marker', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');

        render(
            <LocaleProvider>
                <SettingsSetRoseControl context={context()} />
            </LocaleProvider>,
        );

        expect(screen.getByRole('button', { name: 'وردي' }))
            .toHaveAttribute('data-canonical-operation', SETTINGS_SET_ROSE_OPERATION_ID);
    });

    it('fails closed when the tenant settings surface is not authorized', () => {
        render(
            <LocaleProvider>
                <SettingsSetRoseControl context={context([])} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: 'Rose' })).not.toBeInTheDocument();
    });
});
