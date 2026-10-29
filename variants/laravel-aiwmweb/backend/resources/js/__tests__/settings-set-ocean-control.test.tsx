import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_SET_OCEAN_OPERATION_ID,
    SETTINGS_THEME_STORAGE_KEY,
    SettingsSetOceanControl,
} from '../settings-set-ocean-control';

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

describe('AIMW-CONT-659BEF8765 Settings SetOcean', () => {
    it('applies and persists ocean immediately without an HTTP mutation', async () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        const user = userEvent.setup();

        render(<LocaleProvider><SettingsSetOceanControl context={context()} /></LocaleProvider>);

        const ocean = screen.getByRole('button', { name: 'Ocean' });
        expect(SETTINGS_SET_OCEAN_OPERATION_ID).toBe('AIMW-CONT-659BEF8765');
        expect(ocean).toHaveAttribute('data-canonical-operation', SETTINGS_SET_OCEAN_OPERATION_ID);
        expect(ocean).toHaveAttribute('data-source-handler', 'SetOcean');
        expect(ocean).toHaveAttribute('aria-pressed', 'false');

        await user.click(ocean);

        expect(window.localStorage.getItem(SETTINGS_THEME_STORAGE_KEY)).toBe('ocean');
        expect(document.documentElement.dataset.theme).toBe('ocean');
        expect(ocean).toHaveAttribute('aria-pressed', 'true');
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('renders persisted ocean state and tracks shared shell changes', async () => {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'ocean');
        document.documentElement.dataset.theme = 'ocean';

        render(<LocaleProvider><SettingsSetOceanControl context={context()} /></LocaleProvider>);
        const ocean = screen.getByRole('button', { name: 'Ocean' });
        expect(ocean).toHaveAttribute('aria-pressed', 'true');

        document.documentElement.dataset.theme = 'slate';
        await vi.waitFor(() => expect(ocean).toHaveAttribute('aria-pressed', 'false'));
    });

    it('renders Arabic copy with the exact operation marker', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        render(<LocaleProvider><SettingsSetOceanControl context={context()} /></LocaleProvider>);
        expect(screen.getByRole('button', { name: 'أزرق' }))
            .toHaveAttribute('data-canonical-operation', SETTINGS_SET_OCEAN_OPERATION_ID);
    });

    it('fails closed without tenant settings permission', () => {
        render(<LocaleProvider><SettingsSetOceanControl context={context([])} /></LocaleProvider>);
        expect(screen.queryByRole('button', { name: 'Ocean' })).not.toBeInTheDocument();
    });
});
