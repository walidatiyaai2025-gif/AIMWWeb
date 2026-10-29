import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_APPEARANCE_ANCHOR_OPERATION_ID,
    SETTINGS_APPEARANCE_TARGET_ID,
    SettingsAppearanceAnchorControl,
} from '../settings-appearance-anchor-control';
import { SettingsAppearanceLightControl } from '../settings-appearance-light-control';

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
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('AIMW-CONT-918BE3DBF1 Settings appearance anchor', () => {
    it('renders the canonical same-page appearance link to the real appearance control without HTTP mutation', () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);

        render(
            <LocaleProvider>
                <SettingsAppearanceAnchorControl context={context(['tenant.view'])} />
                <SettingsAppearanceLightControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        const link = screen.getByRole('link', { name: /^Appearance and colors$/i });
        expect(link).toHaveAttribute('href', '#appearance');
        expect(link).toHaveAttribute('aria-controls', SETTINGS_APPEARANCE_TARGET_ID);
        expect(link).toHaveAttribute('data-canonical-operation', SETTINGS_APPEARANCE_ANCHOR_OPERATION_ID);
        expect(SETTINGS_APPEARANCE_ANCHOR_OPERATION_ID).toBe('AIMW-CONT-918BE3DBF1');

        const target = document.getElementById(SETTINGS_APPEARANCE_TARGET_ID);
        expect(target).not.toBeNull();
        expect(target).toHaveAttribute('data-settings-section', 'appearance');
        expect(screen.getByText('Display mode')).toBeInTheDocument();
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('renders Arabic copy while preserving the exact same fragment target', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');

        render(
            <LocaleProvider>
                <SettingsAppearanceAnchorControl context={context(['tenant.view'])} />
                <SettingsAppearanceLightControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        const link = screen.getByRole('link', { name: 'المظهر والألوان' });
        expect(link).toHaveAttribute('href', '#appearance');
        expect(document.getElementById('appearance')).not.toBeNull();
    });

    it('fails closed without tenant.view and exposes neither navigation nor target', () => {
        render(
            <LocaleProvider>
                <SettingsAppearanceAnchorControl context={context([])} />
                <SettingsAppearanceLightControl context={context([])} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('link', { name: /^Appearance and colors$/i })).not.toBeInTheDocument();
        expect(document.getElementById('appearance')).toBeNull();
    });
});
