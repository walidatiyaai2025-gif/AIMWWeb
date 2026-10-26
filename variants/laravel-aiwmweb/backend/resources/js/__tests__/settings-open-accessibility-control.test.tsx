import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_ACCESSIBILITY_DIALOG_ID,
    SETTINGS_OPEN_ACCESSIBILITY_OPERATION_ID,
    SettingsOpenAccessibilityControl,
} from '../settings-open-accessibility-control';

function context(permissions: string[]): FrontendContext {
    return {
        user: { id: 10, name: 'Alpha User', email: 'alpha@example.test' },
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
    document.documentElement.removeAttribute('data-font-scale');
    document.documentElement.removeAttribute('data-density');
    document.documentElement.removeAttribute('data-contrast');
    document.documentElement.removeAttribute('data-reduced-motion');
    document.getElementById('aiwm-laravel-accessibility-runtime')?.remove();
});

describe(`${SETTINGS_OPEN_ACCESSIBILITY_OPERATION_ID} Settings OpenAccessibility`, () => {
    it('opens and closes a real accessibility display center from the canonical control', () => {
        render(
            <LocaleProvider>
                <SettingsOpenAccessibilityControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        const trigger = screen.getByRole('button', { name: /Open center/i });
        expect(trigger).toHaveAttribute('data-canonical-operation', SETTINGS_OPEN_ACCESSIBILITY_OPERATION_ID);
        expect(trigger).toHaveAttribute('aria-controls', SETTINGS_ACCESSIBILITY_DIALOG_ID);
        expect(trigger).toHaveAttribute('aria-expanded', 'false');

        fireEvent.click(trigger);
        expect(trigger).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByRole('dialog', { name: 'Accessibility & display' })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Close accessibility settings' }));
        expect(screen.queryByTestId('settings-accessibility-dialog')).not.toBeInTheDocument();
    });

    it('persists and applies text size, density, contrast and reduced-motion state', () => {
        render(
            <LocaleProvider>
                <SettingsOpenAccessibilityControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );
        fireEvent.click(screen.getByRole('button', { name: /Open center/i }));

        fireEvent.click(screen.getByRole('button', { name: 'Large' }));
        fireEvent.click(screen.getByRole('button', { name: 'Compact' }));
        fireEvent.click(screen.getByRole('button', { name: 'High contrast' }));
        fireEvent.click(screen.getByRole('button', { name: 'Reduce motion' }));

        expect(document.documentElement).toHaveAttribute('data-font-scale', 'large');
        expect(document.documentElement).toHaveAttribute('data-density', 'compact');
        expect(document.documentElement).toHaveAttribute('data-contrast', 'high');
        expect(document.documentElement).toHaveAttribute('data-reduced-motion', 'true');
        expect(window.localStorage.getItem('aiwm-accessibility-font')).toBe('large');
        expect(window.localStorage.getItem('aiwm-accessibility-density')).toBe('compact');
        expect(window.localStorage.getItem('aiwm-accessibility-contrast')).toBe('high');
        expect(window.localStorage.getItem('aiwm-accessibility-motion')).toBe('reduced');
        expect(document.getElementById('aiwm-laravel-accessibility-runtime')).not.toBeNull();
    });

    it('resets the persisted display state to the source defaults', () => {
        window.localStorage.setItem('aiwm-accessibility-font', 'large');
        window.localStorage.setItem('aiwm-accessibility-density', 'compact');
        window.localStorage.setItem('aiwm-accessibility-contrast', 'high');
        window.localStorage.setItem('aiwm-accessibility-motion', 'reduced');

        render(
            <LocaleProvider>
                <SettingsOpenAccessibilityControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );
        fireEvent.click(screen.getByRole('button', { name: /Open center/i }));
        fireEvent.click(screen.getByRole('button', { name: 'Reset to defaults' }));

        expect(document.documentElement).toHaveAttribute('data-font-scale', 'normal');
        expect(document.documentElement).toHaveAttribute('data-density', 'default');
        expect(document.documentElement).toHaveAttribute('data-contrast', 'normal');
        expect(document.documentElement).toHaveAttribute('data-reduced-motion', 'false');
        expect(window.localStorage.getItem('aiwm-accessibility-font')).toBe('normal');
        expect(window.localStorage.getItem('aiwm-accessibility-density')).toBe('default');
    });

    it('fails closed outside a tenant-visible Settings context', () => {
        render(
            <LocaleProvider>
                <SettingsOpenAccessibilityControl context={context([])} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: /Open center/i })).not.toBeInTheDocument();
    });
});
