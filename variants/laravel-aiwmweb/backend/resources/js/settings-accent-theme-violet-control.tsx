import React, { useState } from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const SETTINGS_SET_VIOLET_OPERATION_ID = 'AIMW-CONT-7D83B09470';
export const SETTINGS_THEME_STORAGE_KEY = 'aiwm-color-theme';

function readVioletActive(): boolean {
    try {
        return window.localStorage.getItem(SETTINGS_THEME_STORAGE_KEY) === 'violet';
    } catch {
        return false;
    }
}

export function applyVioletTheme() {
    document.documentElement.dataset.theme = 'violet';
    try {
        window.localStorage.setItem(SETTINGS_THEME_STORAGE_KEY, 'violet');
    } catch {
        // Match the source appTheme runtime: apply the session theme even when persistence is unavailable.
    }
}

export function SettingsAccentThemeVioletControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canViewSettings = context.permissions.includes('*') || context.permissions.includes('tenant.view');
    const [active, setActive] = useState<boolean>(() => readVioletActive());

    if (!canViewSettings) return null;

    const setViolet = () => {
        applyVioletTheme();
        setActive(true);
    };

    return (
        <section className="toolbar-panel" aria-label={locale === 'ar' ? 'لون النظام' : 'Accent color'}>
            <div>
                <span className="workspace-kicker">ACCENT</span>
                <strong>{locale === 'ar' ? 'لون النظام' : 'Accent color'}</strong>
                <small>{locale === 'ar' ? 'اختر اللون البنفسجي لواجهة مساحة العمل' : 'Apply the Violet workspace accent palette'}</small>
            </div>
            <div className="toolbar-actions">
                <button
                    type="button"
                    className={`btn ${active ? 'primary' : ''}`}
                    aria-pressed={active}
                    data-canonical-operation={SETTINGS_SET_VIOLET_OPERATION_ID}
                    onClick={setViolet}
                >
                    <span aria-hidden="true">●</span>
                    {locale === 'ar' ? 'بنفسجي' : 'Violet'}
                </button>
            </div>
        </section>
    );
}
