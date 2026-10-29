import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_ACCESSIBILITY_ANCHOR_OPERATION_ID,
    SETTINGS_ACCESSIBILITY_TARGET_ID,
    SettingsAccessibilityAnchorControl,
} from '../settings-accessibility-anchor-control';

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
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('AIMW-CONT-6AB0ED6C83 Settings accessibility anchor', () => {
    it('renders the canonical same-page accessibility link with a real target and no HTTP mutation', () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);

        render(
            <LocaleProvider>
                <SettingsAccessibilityAnchorControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        const link = screen.getByRole('link', { name: /^Accessibility$/i });
        expect(link).toHaveAttribute('href', '#accessibility');
        expect(link).toHaveAttribute('aria-controls', SETTINGS_ACCESSIBILITY_TARGET_ID);
        expect(link).toHaveAttribute('data-canonical-operation', SETTINGS_ACCESSIBILITY_ANCHOR_OPERATION_ID);
        expect(SETTINGS_ACCESSIBILITY_ANCHOR_OPERATION_ID).toBe('AIMW-CONT-6AB0ED6C83');

        const target = document.getElementById(SETTINGS_ACCESSIBILITY_TARGET_ID);
        expect(target).not.toBeNull();
        expect(target).toHaveAttribute('data-settings-section', 'accessibility');
        expect(screen.getByText('Accessibility and display')).toBeInTheDocument();
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('renders Arabic copy while preserving the exact same fragment target', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');

        render(
            <LocaleProvider>
                <SettingsAccessibilityAnchorControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        const link = screen.getByRole('link', { name: 'سهولة الاستخدام' });
        expect(link).toHaveAttribute('href', '#accessibility');
        expect(document.getElementById('accessibility')).not.toBeNull();
    });

    it('fails closed without tenant.view and exposes neither navigation nor target section', () => {
        render(
            <LocaleProvider>
                <SettingsAccessibilityAnchorControl context={context([])} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('link', { name: /^Accessibility$/i })).not.toBeInTheDocument();
        expect(document.getElementById('accessibility')).toBeNull();
    });
});
