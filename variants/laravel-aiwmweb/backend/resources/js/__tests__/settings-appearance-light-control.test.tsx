import React from 'react';
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    APPEARANCE_MODE_EVENT,
    SETTINGS_SET_LIGHT_OPERATION_ID,
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
    delete document.documentElement.dataset.mode;
    document.documentElement.style.colorScheme = '';
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('AIMW-CONT-4961F90970 Settings SetLight', () => {
    it('applies light mode immediately, persists it, and exposes the canonical operation marker without HTTP mutation', async () => {
        window.localStorage.setItem('aiwm.mode', 'dark');
        document.documentElement.dataset.mode = 'dark';
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        const eventSpy = vi.fn();
        window.addEventListener(APPEARANCE_MODE_EVENT, eventSpy);

        const user = userEvent.setup();
        render(
            <LocaleProvider>
                <SettingsAppearanceLightControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        const button = screen.getByRole('button', { name: 'Light' });
        expect(button).toHaveAttribute('data-canonical-operation', SETTINGS_SET_LIGHT_OPERATION_ID);
        expect(SETTINGS_SET_LIGHT_OPERATION_ID).toBe('AIMW-CONT-4961F90970');
        expect(button).toHaveAttribute('aria-pressed', 'false');

        await user.click(button);

        expect(window.localStorage.getItem('aiwm.mode')).toBe('light');
        expect(document.documentElement.dataset.mode).toBe('light');
        expect(document.documentElement.style.colorScheme).toBe('light');
        expect(button).toHaveAttribute('aria-pressed', 'true');
        expect(eventSpy).toHaveBeenCalledTimes(1);
        expect(fetchSpy).not.toHaveBeenCalled();

        window.removeEventListener(APPEARANCE_MODE_EVENT, eventSpy);
    });

    it('restores the authoritative local appearance state and stays synchronized with shell appearance events', () => {
        window.localStorage.setItem('aiwm.mode', 'light');

        render(
            <LocaleProvider>
                <SettingsAppearanceLightControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        const button = screen.getByRole('button', { name: 'Light' });
        expect(button).toHaveAttribute('aria-pressed', 'true');

        act(() => {
            window.dispatchEvent(new CustomEvent(APPEARANCE_MODE_EVENT, { detail: { mode: 'dark' } }));
        });
        expect(button).toHaveAttribute('aria-pressed', 'false');
    });

    it('fails closed without tenant.view and does not change appearance state', () => {
        window.localStorage.setItem('aiwm.mode', 'dark');

        render(
            <LocaleProvider>
                <SettingsAppearanceLightControl context={context([])} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: 'Light' })).not.toBeInTheDocument();
        expect(window.localStorage.getItem('aiwm.mode')).toBe('dark');
    });
});
