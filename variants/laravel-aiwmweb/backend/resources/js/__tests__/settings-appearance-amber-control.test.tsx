import React from 'react';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_SET_AMBER_OPERATION_ID,
    SettingsAppearanceLightControl,
} from '../settings-appearance-light-control';

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

describe('AIMW-CONT-CF4BE4EC3B Settings SetAmber', () => {
    it('applies and persists the canonical amber accent without an HTTP mutation', async () => {
        window.localStorage.setItem('aiwm-color-theme', 'ocean');
        document.documentElement.dataset.theme = 'ocean';
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        const user = userEvent.setup();

        render(
            <LocaleProvider>
                <SettingsAppearanceLightControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        const button = screen.getByRole('button', { name: 'Amber' });
        expect(button).toHaveAttribute('data-canonical-operation', SETTINGS_SET_AMBER_OPERATION_ID);
        expect(button).toHaveAttribute('data-source-handler', 'SetAmber');
        expect(SETTINGS_SET_AMBER_OPERATION_ID).toBe('AIMW-CONT-CF4BE4EC3B');
        expect(button).toHaveAttribute('aria-pressed', 'false');

        await user.click(button);

        expect(window.localStorage.getItem('aiwm-color-theme')).toBe('amber');
        expect(document.documentElement.dataset.theme).toBe('amber');
        expect(button).toHaveAttribute('aria-pressed', 'true');
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('reads the current accent and follows external authoritative DOM theme changes', async () => {
        window.localStorage.setItem('aiwm-color-theme', 'amber');
        document.documentElement.dataset.theme = 'amber';

        render(
            <LocaleProvider>
                <SettingsAppearanceLightControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        const button = screen.getByRole('button', { name: 'Amber' });
        expect(button).toHaveAttribute('aria-pressed', 'true');

        act(() => {
            document.documentElement.dataset.theme = 'ocean';
            window.localStorage.setItem('aiwm-color-theme', 'ocean');
        });

        await waitFor(() => expect(button).toHaveAttribute('aria-pressed', 'false'));
    });

    it('fails closed without tenant.view and preserves the existing accent', () => {
        window.localStorage.setItem('aiwm-color-theme', 'ocean');
        document.documentElement.dataset.theme = 'ocean';

        render(
            <LocaleProvider>
                <SettingsAppearanceLightControl context={context([])} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: 'Amber' })).not.toBeInTheDocument();
        expect(window.localStorage.getItem('aiwm-color-theme')).toBe('ocean');
        expect(document.documentElement.dataset.theme).toBe('ocean');
    });
});
